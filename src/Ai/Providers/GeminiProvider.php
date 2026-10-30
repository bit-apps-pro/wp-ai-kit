<?php

namespace BitApps\AiKit\Ai\Providers;

use BitApps\AiKit\Ai\Engine\SupportsAttachments;
use BitApps\AiKit\Ai\Engine\SupportsStreaming;

/**
 * Google Gemini generateContent API. Gemini accepts only a subset of JSON
 * Schema, so tool schemas are reduced to the keys it knows.
 */
final class GeminiProvider extends AbstractProvider implements SupportsAttachments, SupportsStreaming
{
    public const KEY = 'gemini';

    public const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private const SCHEMA_KEYS = ['type', 'format', 'description', 'nullable', 'enum', 'items', 'properties', 'required', 'minItems', 'maxItems', 'minimum', 'maximum'];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Google Gemini';
    }

    public function attachmentKinds(): array
    {
        return ['image', 'pdf'];
    }

    public function maxAttachmentBytes(): int
    {
        return 15 * 1024 * 1024;
    }

    public function send(array $messages, array $tools, string $system, array $options): array
    {
        return $this->normalize($this->post($this->url($options, 'generateContent'), ['x-goog-api-key' => $this->apiKey], $this->payload($messages, $tools, $system, $options)));
    }

    public function canStream(): bool
    {
        return true;
    }

    /**
     * streamGenerateContent as events: each one a partial response whose parts
     * continue the text. Text pieces are joined back into the parts a whole
     * response has, so a thought signature stays with its part for the next turn.
     */
    public function stream(array $messages, array $tools, string $system, array $options, callable $onText): array
    {
        $parts = [];
        $usage = [];

        $this->postStream($this->url($options, 'streamGenerateContent') . '?alt=sse', ['x-goog-api-key' => $this->apiKey], $this->payload($messages, $tools, $system, $options), static function (string $line) use (&$parts, &$usage, $onText): void {
            $chunk = self::eventData($line);
            if (null === $chunk) {
                return;
            }
            if (\is_array($chunk['usageMetadata'] ?? null)) {
                $usage = $chunk['usageMetadata'];
            }
            foreach ((array) ($chunk['candidates'][0]['content']['parts'] ?? []) as $part) {
                if (!\is_array($part)) {
                    continue;
                }
                $isText = isset($part['text']) && empty($part['thought']) && !isset($part['functionCall']);
                if ($isText && '' !== (string) $part['text']) {
                    $onText((string) $part['text']);
                }
                $last = array_key_last($parts);
                $lastIsText = null !== $last && isset($parts[$last]['text']) && empty($parts[$last]['thought']) && !isset($parts[$last]['functionCall']);
                if ($isText && $lastIsText && !isset($parts[$last]['thoughtSignature'])) {
                    $parts[$last]['text'] .= (string) $part['text'];
                    if (isset($part['thoughtSignature'])) {
                        $parts[$last]['thoughtSignature'] = $part['thoughtSignature'];
                    }

                    continue;
                }
                $parts[] = $part;
            }
        });

        return $this->normalize(['candidates' => [['content' => ['role' => 'model', 'parts' => $parts]]], 'usageMetadata' => $usage]);
    }

    /** @param array<string, mixed> $options */
    private function url(array $options, string $method): string
    {
        return self::ENDPOINT . rawurlencode((string) $options['model']) . ':' . $method;
    }

    /** @return array<string, mixed> */
    private function payload(array $messages, array $tools, string $system, array $options): array
    {
        $payload = [
            'contents'          => $this->toContents($messages),
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'generationConfig'  => ['maxOutputTokens' => (int) ($options['max_tokens'] ?? 4096)],
        ];

        if ([] !== $tools) {
            $payload['tools'] = [['functionDeclarations' => array_map(static function (array $tool) {
                $declaration = ['name' => (string) $tool['name'], 'description' => (string) $tool['description']];
                $schema = self::sanitize((array) ($tool['inputSchema'] ?? []));
                if (!empty($schema['properties'])) {
                    $declaration['parameters'] = $schema;
                }

                return $declaration;
            }, $tools)]];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function sanitize(array $schema): array
    {
        $clean = [];
        foreach ($schema as $key => $value) {
            if (!\in_array($key, self::SCHEMA_KEYS, true)) {
                continue;
            }
            if ('properties' === $key && \is_array($value)) {
                $clean[$key] = array_map(static fn ($property) => \is_array($property) ? self::sanitize($property) : $property, $value);

                continue;
            }
            $clean[$key] = 'items' === $key && \is_array($value) ? self::sanitize($value) : $value;
        }

        return $clean;
    }

    /** @return array<int, array<string, mixed>> */
    private function toContents(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? '');
            if ('user' === $role) {
                $out[] = ['role' => 'user', 'parts' => isset($message['parts']) ? self::inlineParts((array) $message['parts']) : [['text' => (string) ($message['content'] ?? '')]]];
            } elseif ('assistant' === $role) {
                $raw = $message['raw'] ?? null;
                if (self::KEY === ($message['engine'] ?? null) && \is_array($raw) && !empty($raw['parts'])) {
                    $out[] = ['role' => 'model', 'parts' => $raw['parts']];

                    continue;
                }
                $parts = '' !== (string) ($message['text'] ?? '') ? [['text' => (string) $message['text']]] : [];
                foreach ((array) ($message['tool_calls'] ?? []) as $call) {
                    $parts[] = ['functionCall' => ['name' => (string) $call['name'], 'args' => (object) ($call['input'] ?? [])]];
                }
                if ([] !== $parts) {
                    $out[] = ['role' => 'model', 'parts' => $parts];
                }
            } elseif ('tool' === $role) {
                $parts = [];
                foreach ((array) ($message['results'] ?? []) as $result) {
                    $parts[] = ['functionResponse' => ['name' => (string) ($result['name'] ?? ''), 'response' => ['content' => (string) $result['content']]]];
                }
                if ([] !== $parts) {
                    $out[] = ['role' => 'user', 'parts' => $parts];
                }
            }
        }

        return $out;
    }

    /**
     * A user message's parts (AttachmentEngine) as Gemini parts; files inline.
     *
     * @param array<int, array<string, mixed>> $parts
     *
     * @return array<int, array<string, mixed>>
     */
    private static function inlineParts(array $parts): array
    {
        $out = [];
        foreach ($parts as $part) {
            $type = (string) ($part['type'] ?? '');
            if ('text' === $type && '' !== (string) ($part['text'] ?? '')) {
                $out[] = ['text' => (string) $part['text']];
            } elseif ('image' === $type || 'pdf' === $type) {
                $out[] = ['inlineData' => ['mimeType' => (string) $part['mime'], 'data' => (string) $part['data']]];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $response */
    private function normalize(array $response): array
    {
        $candidate = $response['candidates'][0] ?? [];
        $parts = \is_array($candidate['content']['parts'] ?? null) ? $candidate['content']['parts'] : [];
        $text = [];
        $calls = [];
        foreach ($parts as $index => $part) {
            if (!empty($part['functionCall'])) {
                $name = (string) ($part['functionCall']['name'] ?? '');
                $calls[] = ['id' => 'call_' . $index . '_' . $name, 'name' => $name, 'input' => \is_array($part['functionCall']['args'] ?? null) ? $part['functionCall']['args'] : []];
            } elseif (isset($part['text']) && empty($part['thought'])) {
                $text[] = (string) $part['text'];
            }
        }
        $usage = \is_array($response['usageMetadata'] ?? null) ? $response['usageMetadata'] : [];

        return [
            'text'        => implode("\n\n", $text),
            'tool_calls'  => $calls,
            'stop_reason' => [] === $calls ? 'end_turn' : 'tool_use',
            'usage'       => ['input_tokens' => (int) ($usage['promptTokenCount'] ?? 0), 'output_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0)],
            'raw'         => $candidate['content'] ?? null,
        ];
    }
}
