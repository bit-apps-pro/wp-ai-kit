<?php

namespace BitApps\AiKit\Ai;

use BitApps\AiKit\Ai\Engine\EngineInterface;
use BitApps\AiKit\Ai\Engine\SupportsStreaming;
use BitApps\AiKit\Tools\ToolContext;
use BitApps\AiKit\Tools\ToolRunner;

/**
 * Drives one user message to an answer: model turn → tools → model turn …,
 * at most `max_turns` model calls.
 *
 * Every tool runs through the ToolRunner, so the assistant gets exactly the
 * guard, limits, redaction, masking and audit an MCP client gets. A destructive
 * tool pauses the loop: the runner issues a one-time confirmation token, the loop
 * keeps it server-side (the model never sees it, so it cannot approve itself) and
 * returns a `pending` action for the user to approve or cancel; resume() finishes
 * the turn either way.
 */
final class AgentLoop
{
    /** Longest tool result handed to the model; the rest is cut with a note. */
    public const MAX_RESULT_CHARS = 16000;

    /** Tool calls run per model turn; more are answered "not run" so one turn cannot fan out. */
    public const MAX_CALLS_PER_TURN = 8;

    private const WAITING = 'Waiting for the user to confirm or cancel this action in the chat. Do not call it again; tell the user what it will do.';

    /** @var null|callable(array<string, mixed>): void */
    private $listener;

    /** @var array<int, array<string, mixed>> */
    private array $completed = [];

    public function __construct(private EngineInterface $engine, private ToolRunner $runner, private int $maxTurns = 6, private int $maxTokens = 4096, private string $model = '') {}

    /**
     * Report progress while the turn runs, for a streamed reply: `step` (a model
     * call starts), `text` (`delta`: reply text as it arrives; the whole step's text
     * at once from an engine that does not stream) and `tool` (`id`, `name`,
     * `title`, `status`: running, then ok, error, skipped or waiting).
     *
     * @param null|callable(array<string, mixed>): void $listener
     */
    public function onProgress(?callable $listener): self
    {
        $this->listener = $listener;

        return $this;
    }

    /**
     * The steps of the last run() or resume() that finished before a model call
     * failed: model turns with the results of the tools they ran, the approved
     * action's result first after resume(). Tools act on the site, so these are
     * kept even though the reply did not finish; empty when nothing ran.
     *
     * @return array<int, array<string, mixed>>
     */
    public function completed(): array
    {
        return $this->completed;
    }

    /**
     * @param array<int, array<string, mixed>> $messages transcript ending with the user's message
     *
     * @return array{messages: array<int, array<string, mixed>>, pending: ?array<string, mixed>, usage: array<string, int>, stop_reason: string}
     */
    public function run(array $messages, string $system, ToolContext $call): array
    {
        return $this->loop($messages, [], $system, $call);
    }

    /**
     * Finish a turn that paused for confirmation, then let the model continue.
     *
     * @param array<int, array<string, mixed>> $messages transcript ending with the assistant turn that asked
     * @param array<string, mixed>             $pending  what run() returned as `pending`
     */
    public function resume(array $messages, array $pending, bool $approved, string $system, ToolContext $call): array
    {
        $results = (array) ($pending['results'] ?? []);
        $index = (int) ($pending['index'] ?? 0);

        if ($approved) {
            $name = (string) $pending['name'];
            $title = $name;
            foreach ($this->runner->visibleDefinitions($call) as $definition) {
                if ($definition->name() === $name) {
                    $title = $definition->title();
                }
            }
            $tool = ['type' => 'tool', 'id' => (string) $pending['call_id'], 'name' => $name, 'title' => $title];
            $this->emit($tool + ['status' => 'running']);
            $result = $this->runner->call($name, (array) $pending['input'], $call->withConfirmationToken((string) $pending['token']));
            $results[$index] = self::resultEntry((string) $pending['call_id'], $name, $result->textContent(), $result->isError());
            $this->emit($tool + ['status' => $result->isError() ? 'error' : 'ok']);
        } else {
            $results[$index] = self::resultEntry((string) $pending['call_id'], (string) $pending['name'], 'The user cancelled this action. Do not try it again unless they ask.', true);
        }

        $turn = ['role' => 'tool', 'results' => array_values($results)];
        $messages[] = $turn;

        return $this->loop($messages, [$turn], $system, $call);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $appended
     */
    private function loop(array $messages, array $appended, string $system, ToolContext $call): array
    {
        $this->completed = [];
        $tools = array_map(static fn ($tool) => [
            'name'        => $tool->name(),
            'description' => $tool->description(),
            'inputSchema' => $tool->inputSchema(),
        ], array_values($this->runner->visibleDefinitions($call)));

        $titles = [];
        foreach ($this->runner->visibleDefinitions($call) as $definition) {
            $titles[$definition->name()] = $definition->title();
        }

        $usage = ['input_tokens' => 0, 'output_tokens' => 0];
        $stop = 'max_turns';

        for ($turn = 0; $turn < $this->maxTurns; ++$turn) {
            $this->emit(['type' => 'step', 'turn' => $turn]);
            $options = ['max_tokens' => $this->maxTokens, 'model' => $this->model];
            $streams = null !== $this->listener && $this->engine instanceof SupportsStreaming && $this->engine->canStream();
            $sent = self::paired($messages);

            try {
                $response = $streams
                    ? $this->engine->stream($sent, $tools, $system, $options, fn (string $delta) => $this->emit(['type' => 'text', 'delta' => $delta]))
                    : $this->engine->send($sent, $tools, $system, $options);
            } catch (\Throwable $error) {
                $this->completed = $appended;

                throw $error;
            }
            if (!$streams && '' !== (string) $response['text']) {
                $this->emit(['type' => 'text', 'delta' => (string) $response['text']]);
            }
            $usage['input_tokens'] += (int) ($response['usage']['input_tokens'] ?? 0);
            $usage['output_tokens'] += (int) ($response['usage']['output_tokens'] ?? 0);

            $assistant = [
                'role'       => 'assistant',
                'text'       => (string) $response['text'],
                'tool_calls' => (array) $response['tool_calls'],
                'engine'     => $this->engine->key(),
                'raw'        => $response['raw'],
            ];
            $messages[] = $assistant;
            $appended[] = $assistant;
            $stop = (string) $response['stop_reason'];

            if ([] === $assistant['tool_calls']) {
                break;
            }

            $results = [];
            $pending = null;
            foreach ($assistant['tool_calls'] as $index => $toolCall) {
                $name = (string) ($toolCall['name'] ?? '');
                $id = (string) ($toolCall['id'] ?? '');
                $input = \is_array($toolCall['input'] ?? null) ? $toolCall['input'] : [];
                // Only the user approves: a token the model passes is ignored.
                unset($input['confirmation_token']);

                $tool = ['type' => 'tool', 'id' => $id, 'name' => $name, 'title' => $titles[$name] ?? $name];
                if (null !== $pending) {
                    $results[] = self::resultEntry($id, $name, 'Not run: another action in this turn is waiting for the user. Ask again after they decide.', true, true);
                    $this->emit($tool + ['status' => 'skipped']);

                    continue;
                }
                if ($index >= self::MAX_CALLS_PER_TURN) {
                    $results[] = self::resultEntry($id, $name, 'Not run: at most ' . self::MAX_CALLS_PER_TURN . ' tool calls per step. Ask for the rest in the next step.', true, true);
                    $this->emit($tool + ['status' => 'skipped']);

                    continue;
                }

                $this->emit($tool + ['status' => 'running']);
                $result = $this->runner->call($name, $input, $call);
                if ('confirmation_required' === $result->errorCode() && \is_string($result->meta('confirmation_token'))) {
                    $pending = ['call_id' => $id, 'name' => $name, 'input' => $input, 'token' => $result->meta('confirmation_token'), 'index' => \count($results)];
                    $results[] = self::resultEntry($id, $name, self::WAITING, false);
                    $this->emit($tool + ['status' => 'waiting']);

                    continue;
                }

                $results[] = self::resultEntry($id, $name, $result->textContent(), $result->isError());
                $this->emit($tool + ['status' => $result->isError() ? 'error' : 'ok']);
            }

            if (null !== $pending) {
                $pending['results'] = $results;

                return ['messages' => $appended, 'pending' => $pending, 'usage' => $usage, 'stop_reason' => 'confirmation'];
            }

            $toolTurn = ['role' => 'tool', 'results' => $results];
            $messages[] = $toolTurn;
            $appended[] = $toolTurn;
            $stop = 'max_turns';
        }

        return ['messages' => $appended, 'pending' => null, 'usage' => $usage, 'stop_reason' => $stop];
    }

    /** @param array<string, mixed> $event */
    private function emit(array $event): void
    {
        if (null !== $this->listener) {
            ($this->listener)($event);
        }
    }

    /**
     * Every model turn that asked for tools followed by their results, as the
     * providers require: a turn whose results were never stored (a reply cut off
     * before this kit kept completed steps) gets "not run" ones, so the chat
     * carries on instead of failing on every message.
     *
     * @param array<int, array<string, mixed>> $messages
     *
     * @return array<int, array<string, mixed>>
     */
    private static function paired(array $messages): array
    {
        $messages = array_values($messages);
        $out = [];
        $count = \count($messages);
        foreach ($messages as $i => $message) {
            $out[] = $message;
            $calls = 'assistant' === ($message['role'] ?? '') && \is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];
            if ([] === $calls || ($i + 1 < $count && 'tool' === ($messages[$i + 1]['role'] ?? ''))) {
                continue;
            }
            $out[] = ['role' => 'tool', 'results' => array_map(static fn ($toolCall) => self::resultEntry((string) ($toolCall['id'] ?? ''), (string) ($toolCall['name'] ?? ''), 'Not run: the reply was interrupted before this call ran.', true, true), array_values($calls))];
        }

        return $out;
    }

    /**
     * @param bool $skipped not run at all (the step's call cap, or an action waiting): the screens tell it from a failure
     *
     * @return array{id: string, name: string, content: string, is_error: bool, skipped?: true}
     */
    private static function resultEntry(string $id, string $name, string $content, bool $isError, bool $skipped = false): array
    {
        if (mb_strlen($content) > self::MAX_RESULT_CHARS) {
            $content = mb_substr($content, 0, self::MAX_RESULT_CHARS) . "\n[Result cut at " . self::MAX_RESULT_CHARS . ' characters; ask for less, e.g. a smaller page.]';
        }

        return ['id' => $id, 'name' => $name, 'content' => $content, 'is_error' => $isError] + ($skipped ? ['skipped' => true] : []);
    }
}
