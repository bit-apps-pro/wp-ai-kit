<?php

namespace BitApps\AiKit\Rest;

use BitApps\AiKit\Kit;

/**
 * `/ai/settings/*`: the assistant and MCP settings screens read their state
 * and save their forms here. Saving needs the plugin's manage capability
 * (RestRoutes); the kit validates every key, so the form posts as-is, either
 * flat or under `settings`.
 */
final class SettingsController
{
    public function __construct(private Kit $kit) {}

    /** @param \WP_REST_Request $request */
    public function assistant($request)
    {
        return Response::ok($this->kit->assistantState());
    }

    /** @param \WP_REST_Request $request */
    public function saveAssistant($request)
    {
        $this->kit->saveAiSettings(self::settings($request));

        return Response::ok($this->kit->assistantState());
    }

    /** @param \WP_REST_Request $request */
    public function mcp($request)
    {
        return Response::ok(McpAdminController::stateFor($this->kit, (int) get_current_user_id()));
    }

    /** @param \WP_REST_Request $request */
    public function saveMcp($request)
    {
        $this->kit->saveMcpSettings(self::settings($request));

        return Response::ok(McpAdminController::stateFor($this->kit, (int) get_current_user_id()));
    }

    /**
     * @param \WP_REST_Request $request
     *
     * @return array<string, mixed>
     */
    private static function settings($request): array
    {
        $params = Response::params($request);

        return \array_key_exists('settings', $params) ? Response::array($params, 'settings') : $params;
    }
}
