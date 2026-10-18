<?php

namespace BitApps\AiKit\Ai\Providers;

/**
 * OpenAI Chat Completions API.
 */
final class OpenAiProvider extends AbstractProvider
{
    public const KEY = 'openai';

    public const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'OpenAI';
    }

    public function send(array $messages, array $tools, string $system, array $options): array
    {
        $payload = [
            'model'                 => (string) $options['model'],
            'max_completion_tokens' => (int) ($options['max_tokens'] ?? 4096),
            'messages'              => array_merge([['role' => 'system', 'content' => $system]], $this->toMessages($messages)),
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

        return $this->normalize($this->post(self::ENDPOINT, ['authorization' => 'Bearer ' . $this->apiKey], $payload));
    }

    /** @return array<int, array<string, mixed>> */
    private function toMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? '');
            if ('user' === $role) {
                $out[] = ['role' => 'user', 'content' => (string) ($message['content'] ?? '')];
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

    /** @param array<string, mixed> $response */
    private function normalize(array $response): array
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
}
