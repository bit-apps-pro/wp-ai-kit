<?php

namespace BitApps\AiKit\Ai\Engine;

use BitApps\AiKit\Ai\AiException;

/**
 * The WordPress AI Client (core 7.0+): providers and keys come from the site's
 * Settings → Connectors, so the plugin stores no key and calls no provider itself.
 *
 * Core offers function declarations but no agent loop, so this engine is one
 * turn; AgentLoop runs the tools. External SDK classes are named inline (never in
 * a `use` line) so prefixing tools leave them alone.
 */
final class CoreAiClientEngine implements EngineInterface
{
    public const KEY = 'wordpress';

    /** Whether core's AI Client exists, AI is allowed, and a provider is configured for text. */
    public static function available(): bool
    {
        if (!\function_exists('wp_ai_client_prompt') || !\function_exists('wp_supports_ai') || !wp_supports_ai()) {
            return false;
        }

        try {
            return true === wp_ai_client_prompt('ping')->is_supported_for_text_generation();
        } catch (\Throwable $error) {
            return false;
        }
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'WordPress AI (Settings → Connectors)';
    }

    public function send(array $messages, array $tools, string $system, array $options): array
    {
        if (!\function_exists('wp_ai_client_prompt')) {
            throw new AiException('The WordPress AI Client is not available on this site.', 'engine_unavailable');
        }

        try {
            $history = array_map(static fn (array $message) => \WordPress\AiClient\Messages\DTO\Message::fromArray($message), self::toMessages($messages));
            $declarations = array_map(static fn (array $tool) => \WordPress\AiClient\Tools\DTO\FunctionDeclaration::fromArray($tool), self::toDeclarations($tools));
        } catch (\Throwable $error) {
            throw new AiException('The conversation could not be prepared for the AI provider: ' . $error->getMessage(), 'bad_request');
        }

        if ([] === $history) {
            throw new AiException('Nothing to send.', 'bad_request');
        }

        $builder = wp_ai_client_prompt($history)
            ->using_system_instruction($system)
            ->using_max_tokens((int) ($options['max_tokens'] ?? 4096))
        ;

        if ([] !== $declarations) {
            $builder = $builder->using_function_declarations(...$declarations);
        }

        $model = (string) ($options['model'] ?? '');
        if ('' !== $model) {
            $builder = $builder->using_model_preference($model);
        }

        $result = $builder->generate_text_result();
        if (is_wp_error($result)) {
            throw new AiException($result->get_error_message(), (string) $result->get_error_code());
        }

        return self::normalize($result->toMessage()->toArray(), $result->getTokenUsage()->toArray());
    }

    /**
     * Transcript → core message arrays. Tool results go back as a user message
     * of function responses, which is how the SDK expects them.
     *
     * @param array<int, array<string, mixed>> $messages
     *
     * @return array<int, array<string, mixed>>
     */
    public static function toMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? '');

            if ('user' === $role) {
                $text = (string) ($message['content'] ?? '');
                if ('' !== trim($text)) {
                    $out[] = ['role' => 'user', 'parts' => [['type' => 'text', 'text' => $text]]];
                }

                continue;
            }

            if ('assistant' === $role) {
                $raw = $message['raw'] ?? null;
                if (self::KEY === ($message['engine'] ?? null) && \is_array($raw) && !empty($raw['parts'])) {
                    $out[] = $raw;

                    continue;
                }

                $parts = [];
                if ('' !== (string) ($message['text'] ?? '')) {
                    $parts[] = ['type' => 'text', 'text' => (string) $message['text']];
                }
                foreach ((array) ($message['tool_calls'] ?? []) as $call) {
                    $parts[] = ['type' => 'function_call', 'functionCall' => ['id' => (string) $call['id'], 'name' => (string) $call['name'], 'args' => (array) ($call['input'] ?? [])]];
                }
                if ([] !== $parts) {
                    $out[] = ['role' => 'model', 'parts' => $parts];
                }

                continue;
            }

            if ('tool' === $role) {
                $parts = [];
                foreach ((array) ($message['results'] ?? []) as $result) {
                    $parts[] = ['type' => 'function_response', 'functionResponse' => [
                        'id'       => (string) ($result['id'] ?? ''),
                        'name'     => (string) ($result['name'] ?? ''),
                        'response' => ['content' => (string) ($result['content'] ?? ''), 'is_error' => !empty($result['is_error'])],
                    ]];
                }
                if ([] !== $parts) {
                    $out[] = ['role' => 'user', 'parts' => $parts];
                }
            }
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $tools
     *
     * @return array<int, array<string, mixed>>
     */
    public static function toDeclarations(array $tools): array
    {
        $out = [];
        foreach ($tools as $tool) {
            $out[] = [
                'name'        => (string) $tool['name'],
                'description' => (string) $tool['description'],
                'parameters'  => self::objectSchema((array) ($tool['inputSchema'] ?? [])),
            ];
        }

        return $out;
    }

    /**
     * Core message array → the engine result. Thought-channel text is the
     * model's reasoning, not the answer, so it is kept only in `raw`.
     *
     * @param array<string, mixed> $message
     * @param array<string, mixed> $usage
     */
    public static function normalize(array $message, array $usage = []): array
    {
        $text = [];
        $calls = [];
        foreach ((array) ($message['parts'] ?? []) as $index => $part) {
            if (isset($part['functionCall']) && \is_array($part['functionCall'])) {
                $name = (string) ($part['functionCall']['name'] ?? '');
                $calls[] = [
                    'id'    => (string) ($part['functionCall']['id'] ?? '') ?: 'call_' . $index . '_' . $name,
                    'name'  => $name,
                    'input' => \is_array($part['functionCall']['args'] ?? null) ? $part['functionCall']['args'] : [],
                ];

                continue;
            }
            if (isset($part['text']) && 'thought' !== ($part['channel'] ?? 'content')) {
                $text[] = (string) $part['text'];
            }
        }

        return [
            'text'        => trim(implode("\n\n", $text)),
            'tool_calls'  => $calls,
            'stop_reason' => [] === $calls ? 'end_turn' : 'tool_use',
            'usage'       => [
                'input_tokens'  => (int) ($usage['promptTokens'] ?? 0),
                'output_tokens' => (int) ($usage['completionTokens'] ?? 0),
            ],
            'raw' => $message,
        ];
    }

    /**
     * Providers reject `"properties": []`; an empty object must encode as `{}`.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function objectSchema(array $schema): array
    {
        $schema['type'] = 'object';
        if (empty($schema['properties'])) {
            $schema['properties'] = new \stdClass();
        }

        return $schema;
    }
}
