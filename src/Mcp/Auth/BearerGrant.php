<?php

namespace BitApps\AiKit\Mcp\Auth;

/**
 * What a valid bearer token (OAuth access token or API key) grants.
 */
final class BearerGrant
{
    /**
     * @param string[] $scopes canonical or plugin scope strings
     */
    public function __construct(
        private int $userId,
        private array $scopes,
        private string $clientRef,
        private string $kind,
        private bool $unattended = false,
        private bool $personalDataAllowed = false
    ) {}

    public function userId(): int
    {
        return $this->userId;
    }

    /** @return string[] */
    public function scopes(): array
    {
        return $this->scopes;
    }

    public function clientRef(): string
    {
        return $this->clientRef;
    }

    /** `oauth` or `api_key`. */
    public function kind(): string
    {
        return $this->kind;
    }

    public function isUnattended(): bool
    {
        return $this->unattended;
    }

    public function personalDataAllowed(): bool
    {
        return $this->personalDataAllowed;
    }
}
