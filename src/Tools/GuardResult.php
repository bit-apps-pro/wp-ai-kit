<?php

namespace BitApps\AiKit\Tools;

/**
 * Why a tool call was allowed or refused, with the HTTP status a transport should use.
 */
final class GuardResult
{
    public const OK = 'ok';

    public const NOT_AUTHENTICATED = 'not_authenticated';

    public const NOT_ALLOWED = 'not_allowed';

    public const MISSING_CAPABILITY = 'missing_capability';

    public const INSUFFICIENT_SCOPE = 'insufficient_scope';

    public const TOOL_DISABLED = 'tool_disabled';

    public const RESOURCE_FORBIDDEN = 'resource_forbidden';

    private const STATUS = [
        self::OK                 => 200,
        self::NOT_AUTHENTICATED  => 401,
        self::NOT_ALLOWED        => 403,
        self::MISSING_CAPABILITY => 403,
        self::INSUFFICIENT_SCOPE => 403,
        self::TOOL_DISABLED      => 403,
        self::RESOURCE_FORBIDDEN => 403,
    ];

    private function __construct(private string $code, private string $message = '', private ?string $requiredScope = null) {}

    public static function allow(): self
    {
        return new self(self::OK);
    }

    public static function deny(string $code, string $message, ?string $requiredScope = null): self
    {
        return new self($code, $message, $requiredScope);
    }

    public function allowed(): bool
    {
        return self::OK === $this->code;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }

    /** The scope to request on step-up (`WWW-Authenticate: … scope="…"`). */
    public function requiredScope(): ?string
    {
        return $this->requiredScope;
    }

    public function httpStatus(): int
    {
        return self::STATUS[$this->code] ?? 403;
    }
}
