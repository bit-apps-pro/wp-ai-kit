<?php

namespace BitApps\AiKit\Settings;

/**
 * What this WordPress offers for AI and MCP, for the settings screen.
 *
 * The one place the kit probes the environment. Nothing here gates the kit's own
 * MCP server — it runs on every supported WordPress — it only explains which
 * optional, WordPress-native extras are active and what a missing one costs.
 */
final class RequirementsReport
{
    public const PASS = 'pass';

    public const WARN = 'warn';

    public const INFO = 'info';

    /**
     * @return array<int, array{id: string, label: string, status: string, detail: string}>
     */
    public static function checks(): array
    {
        global $wp_version;

        $https = \function_exists('wp_is_using_https') ? wp_is_using_https() : str_starts_with((string) home_url(), 'https://');
        $pretty = '' !== (string) get_option('permalink_structure');
        $appPasswords = \function_exists('wp_is_application_passwords_available') && wp_is_application_passwords_available();
        $abilities = \function_exists('wp_register_ability');
        $aiClient = \function_exists('wp_ai_client_prompt');
        $connector = $aiClient && \function_exists('wp_supports_ai') && wp_supports_ai();
        $adapter = class_exists(\WP\MCP\Core\McpAdapter::class);

        return [
            [
                'id'     => 'php',
                'label'  => 'PHP ' . PHP_VERSION,
                'status' => self::PASS,
                'detail' => 'Supported (8.0 or newer).',
            ],
            [
                'id'     => 'wordpress',
                'label'  => 'WordPress ' . (string) $wp_version,
                'status' => self::PASS,
                'detail' => 'The MCP server works on every WordPress version this plugin supports.',
            ],
            [
                'id'     => 'https',
                'label'  => 'HTTPS',
                'status' => $https ? self::PASS : self::WARN,
                'detail' => $https ? 'Credentials travel encrypted.' : 'Application Passwords and OAuth need HTTPS outside local development.',
            ],
            [
                'id'     => 'application_passwords',
                'label'  => 'Application Passwords',
                'status' => $appPasswords ? self::PASS : self::WARN,
                'detail' => $appPasswords ? 'AI clients can sign in with an Application Password.' : 'Disabled on this site (by a plugin, a host policy or missing HTTPS); use OAuth or an API key instead.',
            ],
            [
                'id'     => 'permalinks',
                'label'  => 'Pretty permalinks',
                'status' => $pretty ? self::PASS : self::WARN,
                'detail' => $pretty ? 'OAuth discovery documents can be served.' : 'Needed only for OAuth sign-in (hosted AI apps such as claude.ai and ChatGPT).',
            ],
            [
                'id'     => 'abilities_api',
                'label'  => 'WordPress Abilities API',
                'status' => $abilities ? self::PASS : self::INFO,
                'detail' => $abilities ? 'Tools are also offered as WordPress abilities.' : 'Available from WordPress 6.9; optional.',
            ],
            [
                'id'     => 'ai_client',
                'label'  => 'WordPress AI Client',
                'status' => $connector ? self::PASS : self::INFO,
                'detail' => $connector ? 'The AI Assistant can use the providers configured in WordPress Settings → Connectors.' : ($aiClient ? 'No AI provider connected yet (WordPress Settings → Connectors).' : 'Available from WordPress 7.0; optional.'),
            ],
            [
                'id'     => 'mcp_adapter',
                'label'  => 'MCP Adapter plugin',
                'status' => $adapter ? self::PASS : self::INFO,
                'detail' => $adapter ? 'Tools are also listed on the WordPress MCP Adapter.' : 'Optional; the built-in MCP endpoint does not need it.',
            ],
        ];
    }
}
