<?php

namespace BitApps\AiKit\Abilities;

use BitApps\AiKit\Contract;
use BitApps\AiKit\PluginContext;
use BitApps\AiKit\Tools\ToolContext;
use BitApps\AiKit\Tools\ToolDefinition;
use BitApps\AiKit\Tools\ToolRegistry;
use BitApps\AiKit\Tools\ToolRunner;

/**
 * Mirrors the plugin's tools into the WordPress Abilities API (core 6.9+).
 *
 * The kit's registry stays the source of truth; this bridge only makes the same
 * tools visible to anything built on abilities — the core AI Client, the MCP
 * Adapter plugin, future core features. On sites without the API it does nothing.
 *
 * - Every call still goes through ToolRunner (channel `abilities`), so the guard,
 *   redaction, masking and audit apply exactly as for MCP.
 * - Destructive tools are not mirrored: an ability call cannot carry the two-step
 *   confirmation the kit requires for them.
 * - Ability names derive from the MCP name (`bit-pi/list-flows` for `list_flows`);
 *   core accepts only `[a-z0-9-]+/[a-z0-9-]+`.
 * - `meta.mcp.public` is false so the MCP Adapter's default server does not expose
 *   them unless the site asks for it; `show_in_rest` follows the site setting.
 */
final class AbilitiesBridge
{
    private bool $registered = false;

    /**
     * @param array{expose_on_default_server?: bool, show_in_rest?: bool} $options
     */
    public function __construct(
        private PluginContext $context,
        private ToolRegistry $registry,
        private ToolRunner $runner,
        private array $options = []
    ) {}

    /** Whether this WordPress provides the Abilities API at all. */
    public static function available(): bool
    {
        return \function_exists('wp_register_ability') && \function_exists('wp_register_ability_category');
    }

    /** Hooked on wp_abilities_api_categories_init. */
    public function registerCategory(): void
    {
        if (!self::available()) {
            return;
        }

        $slug = Contract::abilityCategory($this->context->slug());
        if (\function_exists('wp_has_ability_category') && wp_has_ability_category($slug)) {
            return;
        }

        wp_register_ability_category($slug, [
            'label'       => $this->context->name(),
            'description' => \sprintf('Tools provided by %s.', $this->context->name()),
        ]);
    }

    /** Hooked on wp_abilities_api_init. */
    public function registerAbilities(): void
    {
        if ($this->registered || !self::available()) {
            return;
        }
        $this->registered = true;

        foreach ($this->mirroredTools() as $tool) {
            $name = $tool->abilityName($this->context->slug());
            if (\function_exists('wp_has_ability') && wp_has_ability($name)) {
                continue;
            }

            wp_register_ability($name, $this->abilityArgs($tool));
        }
    }

    /**
     * The ability names this bridge registers (for the MCP Adapter server).
     *
     * @return string[]
     */
    public function abilityNames(): array
    {
        return array_map(fn (ToolDefinition $tool) => $tool->abilityName($this->context->slug()), $this->mirroredTools());
    }

    /**
     * @return array<string, mixed>
     */
    public function abilityArgs(ToolDefinition $tool): array
    {
        $schema = $tool->inputSchema();
        $hasInput = !empty($schema['properties']);

        $args = [
            'label'               => $tool->title(),
            'description'         => $tool->description(),
            'category'            => Contract::abilityCategory($this->context->slug()),
            'execute_callback'    => fn ($input = null) => $this->execute($tool, $input),
            'permission_callback' => fn ($input = null) => $this->permitted($tool),
            'meta'                => [
                'annotations' => [
                    'readonly'    => $tool->isReadOnly(),
                    'destructive' => false,
                    'idempotent'  => $tool->annotations()['idempotent'],
                ],
                'show_in_rest' => (bool) ($this->options['show_in_rest'] ?? false),
                'mcp'          => [
                    'public' => (bool) ($this->options['expose_on_default_server'] ?? false),
                    'type'   => 'tool',
                ],
                'bitapps' => ['plugin' => $this->context->slug(), 'tool' => $tool->name()],
            ],
        ];

        // A tool without arguments registers no schema, so callers may pass null.
        if ($hasInput) {
            $schema['type'] = 'object';
            $args['input_schema'] = $schema;
        }

        return $args;
    }

    /** @return ToolDefinition[] */
    private function mirroredTools(): array
    {
        return array_values(array_filter($this->registry->all(), static fn (ToolDefinition $tool) => !$tool->isDestructive()));
    }

    private function permitted(ToolDefinition $tool): bool
    {
        return $this->runner->isVisible($tool, ToolContext::forCurrentUser(ToolContext::CHANNEL_ABILITIES));
    }

    /**
     * @param mixed $input
     *
     * @return mixed
     */
    private function execute(ToolDefinition $tool, $input)
    {
        $result = $this->runner->call($tool->name(), \is_array($input) ? $input : [], ToolContext::forCurrentUser(ToolContext::CHANNEL_ABILITIES));

        if ($result->isError()) {
            return new \WP_Error((string) $result->errorCode(), $result->textContent());
        }

        return $result->structured() ?? $result->textContent();
    }
}
