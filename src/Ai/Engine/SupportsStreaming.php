<?php

namespace BitApps\AiKit\Ai\Engine;

/**
 * An engine that can hand over its reply text while the model writes it. The
 * result is the same as send()'s; only the text arrives early, through $onText.
 */
interface SupportsStreaming
{
    /** Whether stream() really streams (a wrapping engine answers for the one it wraps). */
    public function canStream(): bool;

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @param array<string, mixed>             $options
     * @param callable(string): void           $onText   each new piece of reply text
     *
     * @return array{text: string, tool_calls: array<int, array<string, mixed>>, stop_reason: string, usage: array<string, int>, raw: mixed}
     *
     * @throws \BitApps\AiKit\Ai\AiException
     */
    public function stream(array $messages, array $tools, string $system, array $options, callable $onText): array;
}
