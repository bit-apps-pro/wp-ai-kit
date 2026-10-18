<?php

namespace BitApps\AiKit\Ai\Providers;

use BitApps\AiKit\Ai\AiException;
use BitApps\AiKit\Ai\Engine\EngineInterface;

/**
 * Shared HTTP for the direct provider adapters (ported from Bit CRM Pro, without
 * streaming and without a configurable base URL: the endpoints are fixed, so a
 * stored key can never be sent to an address someone typed in).
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
        if ('' === $this->apiKey) {
            throw new AiException('No API key is saved for the AI assistant.', 'missing_api_key');
        }

        $response = wp_safe_remote_post($url, [
            'timeout' => self::TIMEOUT,
            'headers' => $headers + ['content-type' => 'application/json'],
            'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            throw new AiException($response->get_error_message(), 'connection_error');
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $decoded = \is_array($decoded) ? $decoded : [];

        if (200 !== $status) {
            $message = $decoded['error']['message'] ?? $decoded['message'] ?? 'The AI provider rejected the request.';
            $type = $decoded['error']['type'] ?? $decoded['error']['status'] ?? 'api_error';

            throw new AiException(\is_string($message) ? $message : 'The AI provider rejected the request.', \is_string($type) ? $type : 'api_error', $status);
        }

        return $decoded;
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
}
