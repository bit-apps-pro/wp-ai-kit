<?php

namespace BitApps\AiKit\Rest;

use BitApps\AiKit\Audit\SearchableAuditLog;
use BitApps\AiKit\Kit;
use BitApps\AiKit\Settings\FeatureFlags;

/**
 * `/ai/mcp/*`: the MCP server screen's state, the activity log, API keys and
 * connected OAuth apps.
 *
 * Users with the plugin's manage capability see and revoke every user's keys
 * and connections; other users who may use AI see only their own, and may
 * create keys that act as themselves.
 */
final class McpAdminController
{
    public function __construct(private Kit $kit) {}

    /** @param \WP_REST_Request $request */
    public function state($request)
    {
        return Response::ok(self::stateFor($this->kit, $this->userId()));
    }

    /**
     * One page of the activity log with the values the filter menus offer:
     * `{rows, total, filters}`.
     *
     * @param \WP_REST_Request $request
     */
    public function activity($request)
    {
        $params = Response::params($request);
        $filters = array_filter(
            array_intersect_key($params, array_flip(array_merge(SearchableAuditLog::FILTERS, ['from', 'to']))),
            static fn ($value): bool => \is_scalar($value)
        );
        // Without the manage capability a user sees their own calls only.
        $ownOnly = $this->ownOnly();
        if (null !== $ownOnly) {
            $filters['user_id'] = $ownOnly;
        }
        $result = $this->kit->auditSearch($filters, Response::int($params, 'page', 1), Response::int($params, 'per_page', 25));

        foreach ($result['rows'] as $index => $row) {
            $result['rows'][$index]['user'] = self::userName((int) ($row['user_id'] ?? 0));
        }
        $result['filters'] = $this->filterMenus();

        return Response::ok($result);
    }

    /** @param \WP_REST_Request $request */
    public function clearActivity($request)
    {
        if (!$this->kit->clearAudit()) {
            return Response::error('ai_audit_not_clearable', 'This site\'s audit log cannot be cleared from here.', 501);
        }

        return Response::ok(['rows' => [], 'total' => 0, 'filters' => $this->filterMenus()]);
    }

    /** @param \WP_REST_Request $request */
    public function apiKeys($request)
    {
        return Response::ok(self::stateFor($this->kit, $this->userId())['api_keys']);
    }

    /**
     * Create a key that acts as the signed-in user. The key itself is in this
     * reply only; the site stores a hash.
     *
     * @param \WP_REST_Request $request
     */
    public function createApiKey($request)
    {
        $params = Response::params($request);
        if (!$this->kit->features()->isEnabled(FeatureFlags::API_KEYS)) {
            return Response::error('ai_api_keys_off', 'API keys are switched off on this site.', 409);
        }
        $days = isset($params['expires_days']) && '' !== (string) $params['expires_days'] && 'never' !== $params['expires_days'] ? Response::int($params, 'expires_days') : null;
        // A key that never expires, or never pauses for confirmation, overrides the site's safety settings: managers only.
        if (null !== $this->ownOnly() && (null === $days || Response::bool($params, 'unattended', false))) {
            return Response::error('ai_manage_forbidden', 'Only a site administrator may create a key that never expires or never asks for confirmation.', 403);
        }
        $created = $this->kit->createApiKey(
            $this->userId(),
            Response::string($params, 'name'),
            Response::string($params, 'access'),
            $days,
            (bool) Response::bool($params, 'unattended', false)
        );
        if (is_wp_error($created)) {
            return Response::error((string) $created->get_error_code(), $created->get_error_message(), 422);
        }

        return Response::ok(['key' => $created['key'], 'record' => $created['record'], 'state' => self::stateFor($this->kit, $this->userId())], 201);
    }

    /** @param \WP_REST_Request $request */
    public function revokeApiKey($request)
    {
        if (!$this->kit->apiKeys()->revoke(Response::urlParam($request, 'id'), $this->ownOnly())) {
            return Response::error('ai_api_key_not_found', 'That key does not exist.', 404);
        }

        return Response::ok(self::stateFor($this->kit, $this->userId()));
    }

    /** @param \WP_REST_Request $request */
    public function connections($request)
    {
        return Response::ok(self::stateFor($this->kit, $this->userId())['oauth']);
    }

    /** @param \WP_REST_Request $request */
    public function revokeConnection($request)
    {
        if (!$this->kit->revokeOAuthConnection(Response::urlParam($request, 'id'), $this->ownOnly())) {
            return Response::error('ai_connection_not_found', 'That connection no longer exists.', 404);
        }

        return Response::ok(self::stateFor($this->kit, $this->userId()));
    }

    /**
     * Disconnect every app: all of them for a manager, the user's own otherwise.
     *
     * @param \WP_REST_Request $request
     */
    public function revokeAllConnections($request)
    {
        $ownOnly = $this->ownOnly();
        if (null === $ownOnly) {
            $this->kit->revokeAllOAuthConnections();
        } else {
            foreach ($this->kit->oauthConnections() as $connection) {
                if ((int) $connection['user_id'] === $ownOnly) {
                    $this->kit->revokeOAuthConnection((string) $connection['id'], $ownOnly);
                }
            }
        }

        return Response::ok(self::stateFor($this->kit, $this->userId()));
    }

    /**
     * The MCP screen's state as one user may see it: every key carries its
     * owner's name; a user without the manage capability sees only their own
     * keys and connections.
     *
     * @return array<string, mixed>
     */
    public static function stateFor(Kit $kit, int $userId): array
    {
        $state = $kit->adminState();
        foreach ($state['api_keys']['keys'] ?? [] as $index => $key) {
            $state['api_keys']['keys'][$index]['user'] = self::userName((int) ($key['user_id'] ?? 0));
        }
        if ($kit->context()->canManage($userId)) {
            return $state;
        }

        $own = static fn ($row) => (int) ($row['user_id'] ?? 0) === $userId;
        $state['api_keys']['keys'] = array_values(array_filter($state['api_keys']['keys'] ?? [], $own));
        $state['oauth']['connections'] = array_values(array_filter($state['oauth']['connections'] ?? [], $own));
        $state['recent'] = array_values(array_filter($state['recent'] ?? [], $own));

        return $state;
    }

    private function userId(): int
    {
        return (int) get_current_user_id();
    }

    /** The user whose rows alone may be touched; null for a manager. */
    private function ownOnly(): ?int
    {
        return $this->kit->context()->canManage($this->userId()) ? null : $this->userId();
    }

    /**
     * The values each filter menu offers; users as `{id, name}`.
     *
     * @return array<string, array<int, mixed>>
     */
    private function filterMenus(): array
    {
        $facets = $this->kit->auditFacets();
        // Apps, records and people come from everyone's calls: a manager's menus only.
        $ownOnly = $this->ownOnly();
        if (null !== $ownOnly) {
            $facets = array_intersect_key($facets, array_flip(['tool', 'status', 'channel']));
            $facets['user'] = [['id' => $ownOnly, 'name' => self::userName($ownOnly)]];

            return $facets;
        }
        $users = [];
        foreach ($facets['user_id'] ?? [] as $userId) {
            $users[] = ['id' => (int) $userId, 'name' => self::userName((int) $userId)];
        }
        unset($facets['user_id']);
        $facets['user'] = $users;

        return $facets;
    }

    private static function userName(int $userId): string
    {
        $user = $userId > 0 ? get_userdata($userId) : false;

        return \is_object($user) ? (string) ($user->display_name ?? '') : '';
    }
}
