<?php

namespace BitApps\AiKit\Mcp\OAuth;

/**
 * Where an authorization server keeps its registered clients and grants.
 *
 * Two keyed collections, each read and written whole:
 *
 * - clients: client_id => ['client_name', 'redirect_uris', 'created', 'kind' (dcr|cimd), …]
 * - grants:  sha256(access token) => ['id' (stable across rotation), 'refresh_hash',
 *            'previous_refresh_hashes', 'client_id', 'client_name', 'scope', 'user_id',
 *            'resource', 'personal_data', 'access_expires', 'refresh_expires',
 *            'created', 'last_used']
 *
 * This is Bit CRM's settings-row format plus `id`, `previous_refresh_hashes` and
 * `personal_data`, so CRM can keep its rows by implementing this interface over
 * its settings table. A plugin with many connections can back it with a table.
 */
interface OAuthStorageInterface
{
    /** @return array<string, array<string, mixed>> */
    public function clients(): array;

    /** @param array<string, array<string, mixed>> $clients */
    public function saveClients(array $clients): void;

    /** @return array<string, array<string, mixed>> */
    public function grants(): array;

    /** @param array<string, array<string, mixed>> $grants */
    public function saveGrants(array $grants): void;
}
