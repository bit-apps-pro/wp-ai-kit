<?php

namespace BitApps\AiKit\Tests\Ai;

use BitApps\AiKit\Ai\AiException;
use BitApps\AiKit\Ai\Engine\EngineInterface;

/** A model that answers from a script, for tests. */
final class ScriptedEngine implements EngineInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $script = [];

    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    public ?AiException $fail = null;

    public function key(): string
    {
        return 'scripted';
    }

    public function label(): string
    {
        return 'Scripted';
    }

    public function send(array $messages, array $tools, string $system, array $options): array
    {
        $this->calls[] = ['messages' => $messages, 'tools' => $tools, 'system' => $system, 'options' => $options];
        if (null !== $this->fail) {
            throw $this->fail;
        }

        $next = array_shift($this->script) ?? ['text' => 'Done.', 'tool_calls' => []];

        return $next + ['stop_reason' => [] === $next['tool_calls'] ? 'end_turn' : 'tool_use', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5], 'raw' => null];
    }
}
