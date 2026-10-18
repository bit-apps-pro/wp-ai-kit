<?php

namespace BitApps\AiKit\Mcp\Server;

/**
 * JSON-RPC 2.0 framing for MCP messages.
 */
final class JsonRpc
{
    public const PARSE_ERROR = -32700;

    public const INVALID_REQUEST = -32600;

    public const METHOD_NOT_FOUND = -32601;

    public const INVALID_PARAMS = -32602;

    public const INTERNAL_ERROR = -32603;

    /**
     * Decode a request body into one message or a batch.
     *
     * @return array{ok: bool, batch: bool, messages: array<int, array<string, mixed>>, error: ?array<string, mixed>}
     */
    /** Messages accepted in one batch. */
    public const MAX_BATCH = 20;

    /**
     * @param mixed $id
     * @param mixed $result
     *
     * @return array<string, mixed>
     */
    public static function result($id, $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @param mixed $id
     * @param mixed $data
     *
     * @return array<string, mixed>
     */
    public static function error($id, int $code, string $message, $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if (null !== $data) {
            $error['data'] = $data;
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }

    public static function decode(string $body): array
    {
        $decoded = json_decode($body, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            return ['ok' => false, 'batch' => false, 'messages' => [], 'error' => self::error(null, self::PARSE_ERROR, 'Parse error')];
        }

        if (!\is_array($decoded) || [] === $decoded) {
            return ['ok' => false, 'batch' => false, 'messages' => [], 'error' => self::error(null, self::INVALID_REQUEST, 'Invalid Request')];
        }

        // A batch is a JSON array; an object with keys "0", "1" decodes the same in PHP, so look at the text.
        $isBatch = str_starts_with(ltrim($body), '[');
        if ($isBatch && \count($decoded) > self::MAX_BATCH) {
            return ['ok' => false, 'batch' => true, 'messages' => [], 'error' => self::error(null, self::INVALID_REQUEST, 'At most ' . self::MAX_BATCH . ' messages per batch.')];
        }

        return ['ok' => true, 'batch' => $isBatch, 'messages' => $isBatch ? array_values($decoded) : [$decoded], 'error' => null];
    }
}
