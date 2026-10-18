<?php

namespace BitApps\AiKit\Tools;

/**
 * Who is calling a tool, through which channel, with which grant.
 *
 * Transports build it: the MCP transport from the authenticated request, the
 * assistant from the logged-in admin, WP-CLI from `--user`. `scopes` is null
 * when the caller is not scope-limited (cookie session, Application Password,
 * assistant, CLI); OAuth tokens and API keys carry explicit scopes.
 */
final class ToolContext
{
    public const CHANNEL_MCP = 'mcp';

    public const CHANNEL_ASSISTANT = 'assistant';

    public const CHANNEL_ABILITIES = 'abilities';

    public const CHANNEL_CLI = 'cli';

    public const CHANNEL_DIRECT = 'direct';

    private const CHANNELS = [self::CHANNEL_MCP, self::CHANNEL_ASSISTANT, self::CHANNEL_ABILITIES, self::CHANNEL_CLI, self::CHANNEL_DIRECT];

    private string $channel;

    private int $userId;

    private ?string $clientRef;

    /** @var null|string[] */
    private ?array $scopes;

    private ?string $confirmationToken;

    /**
     * @param null|string[] $scopes null = not scope-limited
     */
    public function __construct(
        string $channel,
        int $userId,
        ?array $scopes = null,
        ?string $clientRef = null,
        ?string $confirmationToken = null,
        private bool $unattended = false,
        private bool $personalDataAllowed = false
    ) {
        $this->channel = \in_array($channel, self::CHANNELS, true) ? $channel : self::CHANNEL_DIRECT;
        $this->userId = max(0, $userId);
        $this->scopes = null === $scopes ? null : array_values(array_unique(array_filter($scopes, 'is_string')));
        $this->clientRef = null === $clientRef || '' === $clientRef ? null : $clientRef;
        $this->confirmationToken = null === $confirmationToken || '' === $confirmationToken ? null : $confirmationToken;
    }

    /** A context for the current WordPress user. */
    public static function forCurrentUser(string $channel, ?array $scopes = null, ?string $clientRef = null): self
    {
        return new self($channel, \function_exists('get_current_user_id') ? (int) get_current_user_id() : 0, $scopes, $clientRef);
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function clientRef(): ?string
    {
        return $this->clientRef;
    }

    /** @return null|string[] */
    public function scopes(): ?array
    {
        return $this->scopes;
    }

    public function isScopeLimited(): bool
    {
        return null !== $this->scopes;
    }

    public function confirmationToken(): ?string
    {
        return $this->confirmationToken;
    }

    /** Automation keys that never pause for confirmation. */
    public function isUnattended(): bool
    {
        return $this->unattended;
    }

    /** Whether this grant may see unmasked personal data. */
    public function personalDataAllowed(): bool
    {
        return $this->personalDataAllowed;
    }

    public function withConfirmationToken(?string $token): self
    {
        $copy = clone $this;
        $copy->confirmationToken = null === $token || '' === $token ? null : $token;

        return $copy;
    }
}
