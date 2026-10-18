<?php

namespace BitApps\AiKit\Ai\Providers;

/**
 * Anthropic Messages API.
 */
final class ClaudeProvider extends AbstractProvider
{
    public const KEY = 'claude';

    public const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    public const API_VERSION = '2023-06-01';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Anthropic Claude';
    }

    public function send(array $messages, array $tools, string $system, array $options): array
    {
        $payload = [
            'model'      => (string) $options['model'],
            'max_tokens' => (int) ($options['max_tokens'] ?? 4096),
            'system'     => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
            'messages'   => $this->toMessages($messages),
        ];

        if ([] !== $tools) {
            $payload['tools'] = array_map(static fn (array $tool) => [
                'name'         => (string) $tool['name'],
                'description'  => (string) $tool['description'],
                'input_schema' => self::objectSchema((array) ($tool['inputSchema'] ?? [])),
            ], $tools);
        }

        return $this->normalize($this->post(self::ENDPOINT, ['x-api-key' => $this->apiKey, 'anthropic-version' => self::API_VERSION], $payload));
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
                $raw = $message['raw'] ?? null;
                $own = self::KEY === ($message['engine'] ?? null) && \is_array($raw) && [] !== $raw;
                $content = $own ? $raw : [];
                if (!$own) {
                    if ('' !== (string) ($message['text'] ?? '')) {
                        $content[] = ['type' => 'text', 'text' => (string) $message['text']];
                    }
                    foreach ((array) ($message['tool_calls'] ?? []) as $call) {
                        $content[] = ['type' => 'tool_use', 'id' => (string) $call['id'], 'name' => (string) $call['name'], 'input' => (object) ($call['input'] ?? [])];
                    }
                }
                if ([] !== $content) {
                    $out[] = ['role' => 'assistant', 'content' => $content];
                }
            } elseif ('tool' === $role) {
                $blocks = [];
                foreach ((array) ($message['results'] ?? []) as $result) {
                    $blocks[] = [
                        'type'        => 'tool_result',
                        'tool_use_id' => (string) $result['id'],
                        'content'     => [['type' => 'text', 'text' => (string) $result['content']]],
                        'is_error'    => !empty($result['is_error']),
                    ];
                }
                if ([] !== $blocks) {
                    $out[] = ['role' => 'user', 'content' => $blocks];
                }
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $response */
    private function normalize(array $response): array
    {
        $content = \is_array($response['content'] ?? null) ? $response['content'] : [];
        $text = [];
        $calls = [];
        foreach ($content as $block) {
            $type = $block['type'] ?? '';
            if ('text' === $type && '' !== (string) ($block['text'] ?? '')) {
                $text[] = (string) $block['text'];
            } elseif ('tool_use' === $type) {
                $calls[] = ['id' => (string) ($block['id'] ?? ''), 'name' => (string) ($block['name'] ?? ''), 'input' => \is_array($block['input'] ?? null) ? $block['input'] : []];
            }
        }
        $usage = \is_array($response['usage'] ?? null) ? $response['usage'] : [];

        return [
            'text'        => implode("\n\n", $text),
            'tool_calls'  => $calls,
            'stop_reason' => (string) ($response['stop_reason'] ?? 'end_turn'),
            'usage'       => ['input_tokens' => (int) ($usage['input_tokens'] ?? 0), 'output_tokens' => (int) ($usage['output_tokens'] ?? 0)],
            'raw'         => $content,
        ];
    }
}
