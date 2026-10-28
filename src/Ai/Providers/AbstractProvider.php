<?php

namespace BitApps\AiKit\Ai\Providers;

use BitApps\AiKit\Ai\AiException;
use BitApps\AiKit\Ai\Engine\EngineInterface;

/**
 * Shared HTTP for the direct provider adapters (ported from Bit CRM Pro). The
 * built-in providers have fixed endpoints; the one with a base URL a manager sets
 * (OpenAiCompatibleProvider) keeps its key bound to that URL.
 */
abstract class AbstractProvider implements EngineInterface
{
    /** One model call, not the whole loop. */
    public const TIMEOUT = 90;

    public function __construct(protected string $apiKey) {}

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $payload
     *
     * @return array<string, mixed>
     */
    protected function post(string $url, array $headers, array $payload): array
    {
        [$status, $body] = $this->request($url, $headers, $payload);
        $decoded = json_decode($body, true);
        $decoded = \is_array($decoded) ? $decoded : [];
        self::failUnlessOk($status, $decoded);

        return $decoded;
    }

    /**
     * POST and hand each line of the event stream to $onLine as it arrives
     * (WordPress's HTTP API reports body chunks through `requests-request.progress`).
     * The request still goes through wp_safe_remote_post().
     *
     * @param array<string, string>  $headers
     * @param array<string, mixed>   $payload
     * @param callable(string): void $onLine
     */
    protected function postStream(string $url, array $headers, array $payload, callable $onLine): void
    {
        $buffer = '';
        $listener = static function ($data) use (&$buffer, $onLine): void {
            $buffer .= (string) $data;
            while (false !== ($end = strpos($buffer, "\n"))) {
                $line = rtrim(substr($buffer, 0, $end), "\r");
                $buffer = (string) substr($buffer, $end + 1);
                $onLine($line);
            }
        };

        add_action('requests-request.progress', $listener, 10, 1);

        try {
            [$status, $body] = $this->request($url, $headers + ['accept' => 'text/event-stream'], $payload);
        } finally {
            remove_action('requests-request.progress', $listener, 10);
        }

        if (200 !== $status) {
            $decoded = json_decode($body, true);
            self::failUnlessOk($status, \is_array($decoded) ? $decoded : []);
        }
        if ('' !== trim($buffer)) {
            $onLine(trim($buffer));
        }
    }

    /** Each `data:` line of a server-sent event stream as decoded JSON; null for anything else. */
    protected static function eventData(string $line): ?array
    {
        if (!str_starts_with($line, 'data:')) {
            return null;
        }
        $json = trim(substr($line, 5));
        if ('' === $json || '[DONE]' === $json) {
            return null;
        }
        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * Providers reject `"properties": []`; an empty object must encode as `{}`.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    protected static function objectSchema(array $schema): array
    {
        $schema['type'] = 'object';
        if (empty($schema['properties'])) {
            $schema['properties'] = new \stdClass();
        }

        return $schema;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $payload
     *
     * @return array{0: int, 1: string}
     */
    private function request(string $url, array $headers, array $payload): array
    {
        if ('' === $this->apiKey) {
            throw new AiException('No API key is saved for the AI assistant.', 'missing_api_key');
        }

        $response = wp_safe_remote_post($url, [
            'timeout' => self::TIMEOUT,
            'headers'     => $headers + ['content-type' => 'application/json'],
            'body'        => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            throw new AiException($response->get_error_message(), 'connection_error');
        }

        return [(int) wp_remote_retrieve_response_code($response), (string) wp_remote_retrieve_body($response)];
    }

    /** @param array<string, mixed> $decoded */
    private static function failUnlessOk(int $status, array $decoded): void
    {
        if (200 === $status) {
            return;
        }
        $message = $decoded['error']['message'] ?? $decoded['message'] ?? 'The AI provider rejected the request.';
        $type = $decoded['error']['type'] ?? $decoded['error']['status'] ?? 'api_error';

        throw new AiException(\is_string($message) ? $message : 'The AI provider rejected the request.', \is_string($type) ? $type : 'api_error', $status);
    }
}
