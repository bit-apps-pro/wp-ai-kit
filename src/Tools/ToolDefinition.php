<?php

namespace BitApps\AiKit\Tools;

use BitApps\AiKit\Contract;

/**
 * One tool a plugin offers to MCP clients and the AI assistant.
 *
 * The fields mirror `wp_register_ability()` so a definition can be registered
 * with the Abilities API unchanged (see Abilities\AbilitiesBridge), and both
 * Bit PI's `ToolDefinition` objects and Bit CRM's array tools convert to it.
 *
 * The handler receives `(array $args, ToolContext $context)` with arguments
 * already validated against `inputSchema`, and returns a ToolResult, a WP_Error,
 * an MCP result array (`['content' => …]`), or any JSON-serialisable value.
 */
final class ToolDefinition
{
    public const TIER_FREE = 'free';

    public const TIER_PRO = 'pro';

    private string $name;

    private string $title;

    private string $description;

    /** @var array<string, mixed> */
    private array $inputSchema;

    /** @var null|array<string, mixed> */
    private ?array $outputSchema;

    /** @var callable */
    private $handler;

    private ?string $scope;

    /** @var string[] any one of these capabilities is enough; empty = no capability gate */
    private array $capabilities;

    /** @var array{readonly: bool, destructive: bool, idempotent: bool, openWorld: bool} */
    private array $annotations;

    private bool $containsPersonalData;

    private string $tier;

    /** @var null|callable(array, ToolContext): bool */
    private $resourceCheck;

    /** @var array<string, mixed> */
    private array $meta;

    /**
     * @param array<string, mixed> $spec see fromArray()
     */
    private function __construct(array $spec)
    {
        $this->name = $spec['name'];
        $this->title = $spec['title'];
        $this->description = $spec['description'];
        $this->inputSchema = $spec['input_schema'];
        $this->outputSchema = $spec['output_schema'];
        $this->handler = $spec['handler'];
        $this->scope = $spec['scope'];
        $this->capabilities = $spec['capabilities'];
        $this->annotations = $spec['annotations'];
        $this->containsPersonalData = $spec['personal_data'];
        $this->tier = $spec['tier'];
        $this->resourceCheck = $spec['resource_check'];
        $this->meta = $spec['meta'];
    }

    /**
     * Build a definition from an array. Accepted keys (camelCase aliases in
     * brackets, so Bit CRM's tool arrays convert as they are):
     *
     * - `name` (required): MCP tool name, `[A-Za-z0-9_.-]{1,64}`.
     * - `description` (required).
     * - `handler` (required): callable(array $args, ToolContext $ctx).
     * - `title`: human label; defaults to the name.
     * - `input_schema` [`inputSchema`]: JSON Schema object for the arguments.
     * - `output_schema` [`outputSchema`].
     * - `scope`: a plugin scope string; null derives read/write/destructive.
     * - `capability`: string or list of strings, any one suffices.
     * - `annotations`: readonly/destructive/idempotent/openWorld, or the MCP
     *   spellings readOnlyHint/destructiveHint/idempotentHint/openWorldHint.
     * - `personal_data` [`pii`]: whether results may contain personal data.
     * - `tier`: 'free' | 'pro' (display only).
     * - `resource_check`: callable(array $args, ToolContext $ctx): bool.
     * - `meta`: free-form, passed through to the Abilities mirror. One key is
     *   read here: `assistant_preview` (true) marks a tool that, in the assistant
     *   channel, only changes a preview the user applies or discards, so it runs
     *   without the per-call confirmation every other assistant write needs.
     *   The plugin must keep that promise: its handler checks the channel.
     *
     * @param array<string, mixed> $spec
     */
    public static function fromArray(array $spec): self
    {
        $name = $spec['name'] ?? null;
        if (!\is_string($name) || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $name)) {
            throw new \InvalidArgumentException('Tool name must match [A-Za-z0-9_.-]{1,64}.');
        }

        $description = $spec['description'] ?? null;
        if (!\is_string($description) || '' === trim($description)) {
            throw new \InvalidArgumentException("Tool {$name} needs a description.");
        }

        $handler = $spec['handler'] ?? null;
        if (!\is_callable($handler)) {
            throw new \InvalidArgumentException("Tool {$name} needs a callable handler.");
        }

        $input = $spec['input_schema'] ?? $spec['inputSchema'] ?? [];
        if (\is_object($input)) {
            $input = json_decode((string) json_encode($input), true);
        }
        if (!\is_array($input)) {
            throw new \InvalidArgumentException("Tool {$name} input schema must be an array.");
        }

        $output = $spec['output_schema'] ?? $spec['outputSchema'] ?? null;
        if (null !== $output && !\is_array($output)) {
            throw new \InvalidArgumentException("Tool {$name} output schema must be an array.");
        }

        $scope = $spec['scope'] ?? null;
        if (null !== $scope && (!\is_string($scope) || '' === $scope)) {
            throw new \InvalidArgumentException("Tool {$name} scope must be a non-empty string or null.");
        }

        $capabilities = array_values(array_filter((array) ($spec['capability'] ?? []), 'is_string'));

        $tier = $spec['tier'] ?? self::TIER_FREE;
        if (!\in_array($tier, [self::TIER_FREE, self::TIER_PRO], true)) {
            throw new \InvalidArgumentException("Tool {$name} tier must be 'free' or 'pro'.");
        }

        $resourceCheck = $spec['resource_check'] ?? null;
        if (null !== $resourceCheck && !\is_callable($resourceCheck)) {
            throw new \InvalidArgumentException("Tool {$name} resource_check must be callable.");
        }

        return new self([
            'name'           => $name,
            'title'          => \is_string($spec['title'] ?? null) && '' !== $spec['title'] ? $spec['title'] : $name,
            'description'    => $description,
            'input_schema'   => $input,
            'output_schema'  => $output,
            'handler'        => $handler,
            'scope'          => $scope,
            'capabilities'   => $capabilities,
            'annotations'    => self::normaliseAnnotations((array) ($spec['annotations'] ?? [])),
            'personal_data'  => (bool) ($spec['personal_data'] ?? $spec['pii'] ?? false),
            'tier'           => $tier,
            'resource_check' => $resourceCheck,
            'meta'           => \is_array($spec['meta'] ?? null) ? $spec['meta'] : [],
        ]);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** @return array<string, mixed> */
    public function inputSchema(): array
    {
        return $this->inputSchema;
    }

    /** @return null|array<string, mixed> */
    public function outputSchema(): ?array
    {
        return $this->outputSchema;
    }

    public function handler(): callable
    {
        return $this->handler;
    }

    /** @return string[] */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function tier(): string
    {
        return $this->tier;
    }

    public function containsPersonalData(): bool
    {
        return $this->containsPersonalData;
    }

    public function resourceCheck(): ?callable
    {
        return $this->resourceCheck;
    }

    /** @return array<string, mixed> */
    public function meta(): array
    {
        return $this->meta;
    }

    public function isReadOnly(): bool
    {
        return $this->annotations['readonly'];
    }

    public function isDestructive(): bool
    {
        return $this->annotations['destructive'];
    }

    /** @return array{readonly: bool, destructive: bool, idempotent: bool, openWorld: bool} */
    public function annotations(): array
    {
        return $this->annotations;
    }

    /** The plugin's own scope, or the generic level the annotations imply. */
    public function requiredScope(): string
    {
        return $this->scope ?? $this->accessLevel();
    }

    /** read / write / destructive, from the annotations alone. */
    public function accessLevel(): string
    {
        if ($this->annotations['destructive']) {
            return Scopes::DESTRUCTIVE;
        }

        return $this->annotations['readonly'] ? Scopes::READ : Scopes::WRITE;
    }

    /** The Abilities API name, e.g. `bit-pi/list-flows` for `list_flows`. */
    public function abilityName(string $pluginSlug): string
    {
        return Contract::abilityName($pluginSlug, $this->name);
    }

    /**
     * The MCP `tools/list` entry.
     *
     * @param bool $withConfirmation add the optional `confirmation_token` argument
     *                               destructive tools accept on their second call
     *
     * @return array<string, mixed>
     */
    public function toMcp(bool $withConfirmation = false): array
    {
        $input = $this->inputSchema;
        $description = $this->description;

        if ($withConfirmation && $this->isDestructive()) {
            $input['properties'] = (array) ($input['properties'] ?? []);
            $input['properties']['confirmation_token'] = [
                'type'        => 'string',
                'description' => 'Leave empty on the first call. The tool then answers with a token; after the user explicitly confirms the action, call again with the same arguments and this token.',
            ];
            $description .= ' Destructive: runs only after the user confirms (two-step call with confirmation_token).';
        }

        $tool = [
            'name'        => $this->name,
            'title'       => $this->title,
            'description' => $description,
            'inputSchema' => self::objectSchema($input),
            'annotations' => [
                'title'           => $this->title,
                'readOnlyHint'    => $this->annotations['readonly'],
                'destructiveHint' => $this->annotations['destructive'],
                'idempotentHint'  => $this->annotations['idempotent'],
                'openWorldHint'   => $this->annotations['openWorld'],
            ],
        ];

        if (null !== $this->outputSchema) {
            $tool['outputSchema'] = self::objectSchema($this->outputSchema);
        }

        return $tool;
    }

    /**
     * JSON Schema root forced to an object, with empty `properties` kept as `{}`
     * (an empty PHP array would encode as `[]`, which clients reject).
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function objectSchema(array $schema): array
    {
        $schema['type'] = 'object';

        if (empty($schema['properties'])) {
            $schema['properties'] = new \stdClass();
            unset($schema['required']);
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array{readonly: bool, destructive: bool, idempotent: bool, openWorld: bool}
     */
    private static function normaliseAnnotations(array $raw): array
    {
        $readonly = (bool) ($raw['readonly'] ?? $raw['readOnlyHint'] ?? false);
        $destructive = (bool) ($raw['destructive'] ?? $raw['destructiveHint'] ?? false);

        return [
            'readonly'    => $readonly && !$destructive,
            'destructive' => $destructive,
            'idempotent'  => (bool) ($raw['idempotent'] ?? $raw['idempotentHint'] ?? $readonly),
            'openWorld'   => (bool) ($raw['openWorld'] ?? $raw['openWorldHint'] ?? false),
        ];
    }
}
