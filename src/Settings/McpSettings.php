<?php

namespace BitApps\AiKit\Settings;

use BitApps\AiKit\PluginContext;
use BitApps\AiKit\Tools\Scopes;

/**
 * Reads and saves a plugin's MCP settings (`<prefix>mcp_settings`).
 *
 * Every Bit plugin's settings screen posts here, so the stored shape, the
 * defaults and the validation are the same everywhere. Unknown keys are dropped,
 * tool names must exist and numbers are clamped. The option is small and
 * autoloaded: the MCP hooks read it on every REST request.
 */
final class McpSettings
{
    public const DEFAULTS = [
        'enabled'                  => false,
        'access_level'             => Scopes::READ,
        'disabled_tools'           => [],
        'confirm_destructive'      => true,
        'allow_personal_data'      => false,
        'api_keys'                 => false,
        'oauth'                    => true,
        'expose_on_default_server' => false,
        'abilities_in_rest'        => false,
        'calls_per_minute'         => 120,
        'writes_per_minute'        => 30,
        'audit_retention_days'     => 90,
    ];

    private const LIMITS = [
        'calls_per_minute'     => [1, 1000],
        'writes_per_minute'    => [1, 500],
        'audit_retention_days' => [1, 365],
    ];

    public function __construct(private PluginContext $context) {}

    public function optionName(): string
    {
        return $this->context->optionName('mcp_settings');
    }

    /** @return array<string, mixed> stored settings over the defaults */
    public function read(): array
    {
        $stored = get_option($this->optionName(), []);

        return array_merge(self::DEFAULTS, \is_array($stored) ? array_intersect_key($stored, self::DEFAULTS) : []);
    }

    /**
     * Validate and store. Keys not present in $input keep their stored values.
     *
     * @param mixed    $input
     * @param string[] $knownTools tool names that may appear in disabled_tools
     *
     * @return array<string, mixed> the settings now stored
     */
    public function save($input, array $knownTools = []): array
    {
        $input = \is_object($input) ? (array) json_decode((string) wp_json_encode($input), true) : $input;
        $settings = $this->read();

        if (\is_array($input)) {
            foreach (self::DEFAULTS as $key => $default) {
                if (!\array_key_exists($key, $input)) {
                    continue;
                }
                $settings[$key] = $this->clean($key, $input[$key], $default, $knownTools);
            }
        }

        if (!add_option($this->optionName(), $settings, '', 'yes')) {
            update_option($this->optionName(), $settings, true);
        }

        return $settings;
    }

    /**
     * @param mixed    $value
     * @param mixed    $default
     * @param string[] $knownTools
     *
     * @return mixed
     */
    private function clean(string $key, $value, $default, array $knownTools)
    {
        if ('access_level' === $key) {
            return \is_string($value) && \in_array($value, Scopes::levels(), true) ? $value : $default;
        }

        if ('disabled_tools' === $key) {
            $names = array_values(array_unique(array_filter((array) $value, 'is_string')));

            return [] === $knownTools ? $names : array_values(array_intersect($names, $knownTools));
        }

        if (isset(self::LIMITS[$key])) {
            [$min, $max] = self::LIMITS[$key];

            return max($min, min($max, (int) $value));
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
