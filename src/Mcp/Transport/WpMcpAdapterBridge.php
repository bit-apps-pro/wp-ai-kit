<?php

namespace BitApps\AiKit\Mcp\Transport;

use BitApps\AiKit\Abilities\AbilitiesBridge;
use BitApps\AiKit\Contract;
use BitApps\AiKit\PluginContext;

/**
 * Registers the plugin's tools as a server on the WordPress MCP Adapter plugin,
 * when that plugin is active.
 *
 * Additive: the kit's own endpoint keeps working; sites that run the adapter also
 * get the tools on `<namespace>/mcp-adapter` (and, if the site opts in, through the
 * adapter's default server). Adapter classes are named inline with a leading
 * backslash — never imported — because consuming plugins prefix every `use` line.
 */
final class WpMcpAdapterBridge
{
    public const ROUTE = 'mcp-adapter';

    public function __construct(private PluginContext $context, private AbilitiesBridge $abilities) {}

    /** Whether the MCP Adapter plugin is loaded. */
    public static function available(): bool
    {
        return class_exists(\WP\MCP\Core\McpAdapter::class);
    }

    /**
     * Hooked on mcp_adapter_init.
     *
     * @param mixed $adapter the adapter instance the action passes
     */
    public function createServer($adapter = null): void
    {
        if (!\is_object($adapter) || !method_exists($adapter, 'create_server')) {
            return;
        }

        $tools = $this->abilities->abilityNames();
        if ([] === $tools) {
            return;
        }

        $context = $this->context;

        $adapter->create_server(
            Contract::adapterServerId($context->slug()),
            $context->restNamespace(),
            self::ROUTE,
            $context->name(),
            \sprintf('%s tools for AI clients.', $context->name()),
            $context->version(),
            [\WP\MCP\Transport\HttpTransport::class],
            \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
            null,
            $tools,
            [],
            [],
            static fn ($request = null) => \function_exists('is_user_logged_in') && is_user_logged_in() && $context->canUse((int) get_current_user_id())
        );
    }
}
