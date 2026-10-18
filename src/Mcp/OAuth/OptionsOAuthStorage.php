<?php

namespace BitApps\AiKit\Mcp\OAuth;

use BitApps\AiKit\PluginContext;

/**
 * The default store: two non-autoloaded options per plugin
 * (`<prefix>mcp_oauth_clients`, `<prefix>mcp_oauth_grants`).
 *
 * Writes are read-modify-write on one row, so two refreshes in the same instant
 * can lose one write; that client simply signs in again. Expected scale is a few
 * connections per site. Supply an `oauth_storage` service for anything larger.
 */
final class OptionsOAuthStorage implements OAuthStorageInterface
{
    private string $clientsOption;

    private string $grantsOption;

    public function __construct(PluginContext $context)
    {
        $this->clientsOption = $context->optionName('mcp_oauth_clients');
        $this->grantsOption = $context->optionName('mcp_oauth_grants');
    }

    public function clients(): array
    {
        return self::read($this->clientsOption);
    }

    public function saveClients(array $clients): void
    {
        self::write($this->clientsOption, $clients);
    }

    public function grants(): array
    {
        return self::read($this->grantsOption);
    }

    public function saveGrants(array $grants): void
    {
        self::write($this->grantsOption, $grants);
    }

    /** @return string[] */
    public function optionNames(): array
    {
        return [$this->clientsOption, $this->grantsOption];
    }

    /** @return array<string, array<string, mixed>> */
    private static function read(string $option): array
    {
        $value = get_option($option, []);

        return \is_array($value) ? array_filter($value, 'is_array') : [];
    }

    private static function write(string $option, array $value): void
    {
        if (false === get_option($option, false)) {
            add_option($option, $value, '', 'no');

            return;
        }

        update_option($option, $value, false);
    }
}
