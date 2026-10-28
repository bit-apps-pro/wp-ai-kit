<?php

namespace BitApps\AiKit\Ai\Providers;

use BitApps\AiKit\Ai\AiException;
use BitApps\AiKit\Ai\Engine\SupportsAttachments;
use BitApps\AiKit\Ai\Engine\SupportsStreaming;

/**
 * Anthropic Messages API.
 */
final class ClaudeProvider extends AbstractProvider implements SupportsAttachments, SupportsStreaming
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

    public function attachmentKinds(): array
    {
        return ['image', 'pdf'];
    }

    public function maxAttachmentBytes(): int
    {
        return 0;
    }

    public function send(array $messages, array $tools, string $system, array $options): array
    {
        return $this->normalize($this->post(self::ENDPOINT, $this->headers(), $this->payload($messages, $tools, $system, $options)));
    }

    public function canStream(): bool
    {
        return true;
    }

    public function stream(array $messages, array $tools, string $system, array $options, callable $onText): array
    {
        $blocks = [];
        $json = [];
        $response = ['stop_reason' => 'end_turn', 'usage' => []];

        $this->postStream(self::ENDPOINT, $this->headers(), $this->payload($messages, $tools, $system, $options) + ['stream' => true], static function (string $line) use (&$blocks, &$json, &$response, $onText): void {
            $event = self::eventData($line);
            if (null === $event) {
                return;
            }
            $index = (int) ($event['index'] ?? 0);

            switch ($event['type'] ?? '') {
                case 'message_start':
                    $response['usage'] = (array) ($event['message']['usage'] ?? []);

                    break;

                case 'content_block_start':
                    $blocks[$index] = (array) ($event['content_block'] ?? []);
                    $json[$index] = '';

                    break;

                case 'content_block_delta':
                    $delta = (array) ($event['delta'] ?? []);
                    $type = (string) ($delta['type'] ?? '');
                    if ('text_delta' === $type) {
                        $blocks[$index]['text'] = ($blocks[$index]['text'] ?? '') . (string) $delta['text'];
                        $onText((string) $delta['text']);
                    } elseif ('input_json_delta' === $type) {
                        $json[$index] = ($json[$index] ?? '') . (string) ($delta['partial_json'] ?? '');
                    } elseif ('thinking_delta' === $type) {
                        $blocks[$index]['thinking'] = ($blocks[$index]['thinking'] ?? '') . (string) ($delta['thinking'] ?? '');
                    } elseif ('signature_delta' === $type) {
                        $blocks[$index]['signature'] = ($blocks[$index]['signature'] ?? '') . (string) ($delta['signature'] ?? '');
                    }

                    break;

                case 'content_block_stop':
                    // A tool's input arrives as JSON in pieces; the finished block holds it decoded, as send() returns it.
                    if ('tool_use' === ($blocks[$index]['type'] ?? '')) {
                        $input = json_decode('' !== ($json[$index] ?? '') ? $json[$index] : '{}', true);
                        $blocks[$index]['input'] = \is_array($input) ? $input : [];
                    }

                    break;

                case 'message_delta':
                    $response['stop_reason'] = (string) ($event['delta']['stop_reason'] ?? $response['stop_reason']);
                    $response['usage']['output_tokens'] = (int) ($event['usage']['output_tokens'] ?? 0);

                    break;

                case 'error':
                    throw new AiException((string) ($event['error']['message'] ?? 'The AI provider stopped the reply.'), (string) ($event['error']['type'] ?? 'api_error'));
            }
        });

        ksort($blocks);

        return $this->normalize($response + ['content' => array_values($blocks)]);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['x-api-key' => $this->apiKey, 'anthropic-version' => self::API_VERSION];
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

        return $payload;
    }

    /** @return array<int, array<string, mixed>> */
    private function toMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? '');
            if ('user' === $role) {
                $out[] = ['role' => 'user', 'content' => isset($message['parts']) ? self::blocks((array) $message['parts']) : (string) ($message['content'] ?? '')];
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

    /**
     * A user message's parts (AttachmentEngine) as content blocks.
     *
     * @param array<int, array<string, mixed>> $parts
     *
     * @return array<int, array<string, mixed>>
     */
    private static function blocks(array $parts): array
    {
        $blocks = [];
        foreach ($parts as $part) {
            $type = (string) ($part['type'] ?? '');
            if ('text' === $type && '' !== (string) ($part['text'] ?? '')) {
                $blocks[] = ['type' => 'text', 'text' => (string) $part['text']];
            } elseif ('image' === $type) {
                $blocks[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => (string) $part['mime'], 'data' => (string) $part['data']]];
            } elseif ('pdf' === $type) {
                $blocks[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => (string) $part['data']], 'title' => (string) ($part['name'] ?? '')];
            }
        }

        return $blocks;
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
