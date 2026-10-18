<?php

namespace BitApps\AiKit\Ai\Engine;

/**
 * One non-streaming model turn, in the kit's provider-neutral transcript:
 *
 *   ['role' => 'user',      'content' => string]
 *   ['role' => 'assistant', 'text' => string, 'tool_calls' => [['id', 'name', 'input']],
 *                           'engine' => string, 'raw' => mixed]
 *   ['role' => 'tool',      'results' => [['id', 'name', 'content', 'is_error']]]
 *
 * `raw` is the engine's own assistant turn, replayed verbatim when the same
 * engine continues the conversation (Anthropic thinking blocks and Gemini thought
 * signatures must come back unchanged). Engines ignore another engine's `raw`.
 */
interface EngineInterface
{
    /** Stable id stored on assistant turns, e.g. `wordpress`, `claude`. */
    public function key(): string;

    /** Human name for the settings screen and the audit log. */
    public function label(): string;

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools    ['name', 'description', 'inputSchema']
     * @param array<string, mixed>             $options  'model', 'max_tokens'
     *
     * @return array{text: string, tool_calls: array<int, array<string, mixed>>, stop_reason: string, usage: array<string, int>, raw: mixed}
     *
     * @throws \BitApps\AiKit\Ai\AiException
     */
    public function send(array $messages, array $tools, string $system, array $options): array;
}
