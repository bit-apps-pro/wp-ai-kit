<?php

namespace BitApps\AiKit\Ai\Providers;

use BitApps\AiKit\Ai\Engine\SupportsStreaming;

/**
 * The Chat Completions wire format (messages, function tools, tool results), which
 * OpenAI and the many services that copy its API share.
 */
abstract class ChatCompletionsProvider extends AbstractProvider implements SupportsStreaming
{
    public function send(array $messages, array $tools, string $system, array $options): array
    {
        return $this->normalize($this->post($this->endpoint(), $this->headers(), $this->payload($messages, $tools, $system, $options)));
    }

    public function canStream(): bool
    {
        return true;
    }

    public function stream(array $messages, array $tools, string $system, array $options, callable $onText): array
    {
        $payload = $this->payload($messages, $tools, $system, $options) + ['stream' => true] + $this->streamOptions();
        $text = '';
        $calls = [];
        $usage = [];

        $this->postStream($this->endpoint(), $this->headers(), $payload, static function (string $line) use (&$text, &$calls, &$usage, $onText): void {
            $chunk = self::eventData($line);
            if (null === $chunk) {
                return;
            }
            if (\is_array($chunk['usage'] ?? null)) {
                $usage = $chunk['usage'];
            }
            $delta = $chunk['choices'][0]['delta'] ?? null;
            if (!\is_array($delta)) {
                return;
            }
            if (\is_string($delta['content'] ?? null) && '' !== $delta['content']) {
                $text .= $delta['content'];
                $onText($delta['content']);
            }
            // A tool call arrives in pieces keyed by index: the id and name first, the arguments bit by bit.
            foreach ((array) ($delta['tool_calls'] ?? []) as $piece) {
                $index = (int) ($piece['index'] ?? 0);
                $calls[$index] ??= ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
                if (!empty($piece['id'])) {
                    $calls[$index]['id'] = (string) $piece['id'];
                }
                $calls[$index]['function']['name'] .= (string) ($piece['function']['name'] ?? '');
                $calls[$index]['function']['arguments'] .= (string) ($piece['function']['arguments'] ?? '');
            }
        });

        ksort($calls);
        $message = ['role' => 'assistant', 'content' => '' === $text ? null : $text] + ([] === $calls ? [] : ['tool_calls' => array_values($calls)]);

        return $this->normalize(['choices' => [['message' => $message]], 'usage' => $usage]);
    }

    /** Extra fields for a streamed request (OpenAI reports usage in a last chunk when asked; not every compatible service accepts that). */
    protected function streamOptions(): array
    {
        return [];
    }

    /** Where the request goes. */
    abstract protected function endpoint(): string;

    /** The output-token limit's name: OpenAI's current one; compatible services mostly read the older `max_tokens`. */
    protected function tokenField(): string
    {
        return 'max_completion_tokens';
    }

    /** @return array<int, array<string, mixed>> */
    protected function toMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? '');
            if ('user' === $role) {
                $out[] = ['role' => 'user', 'content' => isset($message['parts']) ? self::contentParts((array) $message['parts']) : (string) ($message['content'] ?? '')];
            } elseif ('assistant' === $role) {
                $turn = ['role' => 'assistant', 'content' => (string) ($message['text'] ?? '')];
                $calls = [];
                foreach ((array) ($message['tool_calls'] ?? []) as $call) {
                    $calls[] = ['id' => (string) $call['id'], 'type' => 'function', 'function' => ['name' => (string) $call['name'], 'arguments' => (string) wp_json_encode((object) ($call['input'] ?? []))]];
                }
                if ([] !== $calls) {
                    $turn['tool_calls'] = $calls;
                }
                $out[] = $turn;
            } elseif ('tool' === $role) {
                foreach ((array) ($message['results'] ?? []) as $result) {
                    $out[] = ['role' => 'tool', 'tool_call_id' => (string) $result['id'], 'content' => (string) $result['content']];
                }
            }
        }

        return $out;
    }

    /**
     * A user message's parts (AttachmentEngine) as Chat Completions content parts.
     *
     * @param array<int, array<string, mixed>> $parts
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function contentParts(array $parts): array
    {
        $out = [];
        foreach ($parts as $part) {
            $type = (string) ($part['type'] ?? '');
            if ('text' === $type && '' !== (string) ($part['text'] ?? '')) {
                $out[] = ['type' => 'text', 'text' => (string) $part['text']];
            } elseif ('image' === $type) {
                $out[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $part['mime'] . ';base64,' . $part['data']]];
            } elseif ('pdf' === $type) {
                $out[] = ['type' => 'file', 'file' => ['filename' => (string) ($part['name'] ?? 'document.pdf'), 'file_data' => 'data:application/pdf;base64,' . $part['data']]];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $response */
    protected function normalize(array $response): array
    {
        $choice = $response['choices'][0] ?? [];
        $message = \is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $calls = [];
        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            $input = json_decode((string) ($call['function']['arguments'] ?? ''), true);
            $calls[] = ['id' => (string) ($call['id'] ?? ''), 'name' => (string) ($call['function']['name'] ?? ''), 'input' => \is_array($input) ? $input : []];
        }
        $usage = \is_array($response['usage'] ?? null) ? $response['usage'] : [];

        return [
            'text'        => (string) ($message['content'] ?? ''),
            'tool_calls'  => $calls,
            'stop_reason' => [] === $calls ? 'end_turn' : 'tool_use',
            'usage'       => ['input_tokens' => (int) ($usage['prompt_tokens'] ?? 0), 'output_tokens' => (int) ($usage['completion_tokens'] ?? 0)],
            'raw'         => $message,
        ];
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['authorization' => 'Bearer ' . $this->apiKey];
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @param array<string, mixed>             $options
     *
     * @return array<string, mixed>
     */
    private function payload(array $messages, array $tools, string $system, array $options): array
    {
        $payload = [
            'model'             => (string) $options['model'],
            $this->tokenField() => (int) ($options['max_tokens'] ?? 4096),
            'messages'          => array_merge([['role' => 'system', 'content' => $system]], $this->toMessages($messages)),
        ];

        if ([] !== $tools) {
            $payload['tools'] = array_map(static fn (array $tool) => [
                'type'     => 'function',
                'function' => [
                    'name'        => (string) $tool['name'],
                    'description' => (string) $tool['description'],
                    'parameters'  => self::objectSchema((array) ($tool['inputSchema'] ?? [])),
                ],
            ], $tools);
        }

        return $payload;
    }
}
