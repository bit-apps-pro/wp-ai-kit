<?php

namespace BitApps\AiKit\Ai;

/**
 * A model call that failed: no engine, a rejected key, a provider error, a
 * network failure. `reason` is a stable code the UI can map to help text.
 */
final class AiException extends \RuntimeException
{
    public function __construct(string $message, private string $reason = 'ai_error', private int $httpStatus = 0)
    {
        parent::__construct($message);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
