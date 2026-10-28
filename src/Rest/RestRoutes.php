<?php

namespace BitApps\AiKit\Rest;

use BitApps\AiKit\Contract;
use BitApps\AiKit\Kit;
use BitApps\AiKit\PluginContext;

/**
 * The kit's admin REST routes, `<rest_namespace>/ai/...`: what the chat panel
 * and the settings screens call. A plugin that opts in (PluginContext feature
 * `rest_ui`) writes no controllers; the host's UI talks to these.
 *
 * Authentication is WordPress's: the cookie session with an `X-WP-Nonce`
 * (`wp_rest`) header. Every route then requires the plugin's "may use AI" rule
 * (`can_use`); routes marked `manage` also the plugin's manage capability.
 * The plugin may narrow or widen a decision with the `restPermissionHook` filter.
 *
 * Registration is lazy like the MCP transport's: controllers and the kit's
 * services are built only when a request reaches a route.
 */
final class RestRoutes
{
    /** Conversation, key and connection ids in paths. */
    public const ID = '(?P<id>[A-Za-z0-9_-]{1,64})';

    public const LEVEL_USE = 'use';

    public const LEVEL_MANAGE = 'manage';

    /**
     * route (under Contract::REST_BASE) => [HTTP methods, controller class, method, level].
     *
     * @return array<string, array<int, array{0: string, 1: class-string, 2: string, 3: string}>>
     */
    public static function table(): array
    {
        $id = self::ID;

        return [
            '/assistant/view'                     => [['GET', AssistantController::class, 'view', self::LEVEL_USE]],
            '/assistant/chat'                     => [['POST', AssistantController::class, 'chat', self::LEVEL_USE]],
            '/assistant/stream'                   => [['POST', AssistantController::class, 'stream', self::LEVEL_USE]],
            '/assistant/decide'                   => [['POST', AssistantController::class, 'decide', self::LEVEL_USE]],
            '/assistant/decide/stream'            => [['POST', AssistantController::class, 'decideStream', self::LEVEL_USE]],
            '/assistant/reset'                    => [['POST', AssistantController::class, 'reset', self::LEVEL_USE]],
            '/assistant/history'                  => [['GET', AssistantController::class, 'history', self::LEVEL_USE]],
            "/assistant/conversations/{$id}/open" => [['POST', AssistantController::class, 'open', self::LEVEL_USE]],
            '/assistant/attachments'              => [['POST', AssistantController::class, 'upload', self::LEVEL_USE]],
            "/assistant/attachments/{$id}"        => [['DELETE', AssistantController::class, 'deleteAttachment', self::LEVEL_USE]],
            "/assistant/conversations/{$id}"      => [
                ['PATCH', AssistantController::class, 'update', self::LEVEL_USE],
                ['DELETE', AssistantController::class, 'forget', self::LEVEL_USE],
            ],
            '/settings/assistant' => [
                ['GET', SettingsController::class, 'assistant', self::LEVEL_USE],
                ['POST', SettingsController::class, 'saveAssistant', self::LEVEL_MANAGE],
            ],
            '/settings/mcp' => [
                ['GET', SettingsController::class, 'mcp', self::LEVEL_USE],
                ['POST', SettingsController::class, 'saveMcp', self::LEVEL_MANAGE],
            ],
            '/mcp/state'    => [['GET', McpAdminController::class, 'state', self::LEVEL_USE]],
            '/mcp/activity' => [
                ['GET', McpAdminController::class, 'activity', self::LEVEL_USE],
                ['DELETE', McpAdminController::class, 'clearActivity', self::LEVEL_MANAGE],
            ],
            '/mcp/api-keys' => [
                ['GET', McpAdminController::class, 'apiKeys', self::LEVEL_USE],
                ['POST', McpAdminController::class, 'createApiKey', self::LEVEL_USE],
            ],
            "/mcp/api-keys/{$id}" => [['DELETE', McpAdminController::class, 'revokeApiKey', self::LEVEL_USE]],
            '/mcp/connections'    => [
                ['GET', McpAdminController::class, 'connections', self::LEVEL_USE],
                ['DELETE', McpAdminController::class, 'revokeAllConnections', self::LEVEL_USE],
            ],
            "/mcp/connections/{$id}" => [['DELETE', McpAdminController::class, 'revokeConnection', self::LEVEL_USE]],
        ];
    }

    /**
     * The PluginContext parts a route needs; a plugin that leaves one out gets no
     * such route, so its REST surface matches what its screens show.
     *
     * @return string[]
     */
    public static function requires(string $route): array
    {
        $prefix = static fn (string $start): bool => str_starts_with($route, $start);

        $needs = [];
        if ($prefix('/assistant/') || '/settings/assistant' === $route) {
            $needs[] = PluginContext::FEATURE_ASSISTANT;
        }
        if ($prefix('/mcp/') || '/settings/mcp' === $route) {
            $needs[] = PluginContext::FEATURE_MCP;
        }
        if ('/assistant/history' === $route || $prefix('/assistant/conversations/')) {
            $needs[] = PluginContext::FEATURE_HISTORY;
        }
        if ($prefix('/assistant/attachments')) {
            $needs[] = PluginContext::FEATURE_ATTACHMENTS;
        }
        if ('/assistant/stream' === $route || '/assistant/decide/stream' === $route) {
            $needs[] = PluginContext::FEATURE_STREAM;
        }
        if ('/mcp/activity' === $route) {
            $needs[] = PluginContext::FEATURE_AUDIT;
        }
        if ($prefix('/mcp/api-keys')) {
            $needs[] = PluginContext::FEATURE_API_KEYS;
        }
        if ($prefix('/mcp/connections')) {
            $needs[] = PluginContext::FEATURE_OAUTH;
        }

        return $needs;
    }

    /**
     * @param \Closure(): Kit    $kit     built only when a request arrives
     * @param null|PluginContext $context the plugin's parts; null registers every route
     */
    public static function register(string $namespace, \Closure $kit, ?PluginContext $context = null): void
    {
        foreach (self::table() as $route => $endpoints) {
            if (null !== $context && array_filter(self::requires($route), static fn (string $part): bool => !$context->provides($part))) {
                continue;
            }
            $args = [];
            foreach ($endpoints as [$methods, $controller, $method, $level]) {
                $args[] = [
                    'methods'             => $methods,
                    'callback'            => static fn ($request) => (new $controller($kit()))->{$method}($request),
                    'permission_callback' => self::permission($kit, $route, $level),
                ];
            }
            register_rest_route($namespace, Contract::REST_BASE . $route, $args);
        }
    }

    /**
     * @param \Closure(): Kit $kit
     *
     * @return \Closure(\WP_REST_Request): (bool|\WP_Error)
     */
    public static function permission(\Closure $kit, string $route, string $level): \Closure
    {
        return static function ($request) use ($kit, $route, $level) {
            $context = $kit()->context();
            $userId = (int) get_current_user_id();

            // Signed-out requests are refused before the filter: it may narrow access, never open it to visitors.
            if ($userId <= 0) {
                return Response::error('rest_not_logged_in', 'Sign in to use the AI features.', 401);
            }

            if (!$context->canUse($userId)) {
                $allowed = Response::error('ai_forbidden', 'This account may not use the AI features of ' . $context->name() . '.', 403);
            } elseif (self::LEVEL_MANAGE === $level && !$context->canManage($userId)) {
                $allowed = Response::error('ai_manage_forbidden', 'Only a site administrator may change this.', 403);
            } else {
                $allowed = true;
            }

            return apply_filters(Contract::restPermissionHook($context->slug()), $allowed, $route, $request, $level);
        };
    }
}
