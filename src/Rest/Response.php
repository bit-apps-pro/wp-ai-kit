<?php

namespace BitApps\AiKit\Rest;

/**
 * Request reading and reply building shared by the kit's REST controllers.
 *
 * Replies are plain payloads (no envelope): WordPress serialises the data with
 * the status, and failures are `WP_Error`s core renders as `{code, message,
 * data: {status}}`. A failed chat turn is the exception: it answers with the
 * conversation view (which carries `error` and `reason`) under status 422, so
 * the panel stays in step with what was saved.
 */
final class Response
{
    /**
     * Every parameter of the request in one array: path, query, form body and
     * JSON body, later sources overriding earlier ones.
     *
     * @param \WP_REST_Request $request
     *
     * @return array<string, mixed>
     */
    public static function params($request): array
    {
        $params = [];
        foreach (['get_url_params', 'get_query_params', 'get_body_params', 'get_json_params'] as $method) {
            if (method_exists($request, $method)) {
                $part = $request->{$method}();
                if (\is_array($part)) {
                    $params = array_merge($params, $part);
                }
            }
        }

        return $params;
    }

    /**
     * A path parameter (`{id}`), read from the URL only: a query or body `id`
     * must not change which record a route acts on.
     *
     * @param \WP_REST_Request $request
     */
    public static function urlParam($request, string $key): string
    {
        $params = method_exists($request, 'get_url_params') ? $request->get_url_params() : [];

        return \is_array($params) ? self::string($params, $key) : '';
    }

    /** @param array<string, mixed> $params */
    public static function string(array $params, string $key, string $default = ''): string
    {
        return isset($params[$key]) && \is_scalar($params[$key]) ? (string) $params[$key] : $default;
    }

    /** @param array<string, mixed> $params */
    public static function int(array $params, string $key, int $default = 0): int
    {
        return isset($params[$key]) && is_numeric($params[$key]) ? (int) $params[$key] : $default;
    }

    /** @param array<string, mixed> $params */
    public static function bool(array $params, string $key, ?bool $default = null): ?bool
    {
        if (!\array_key_exists($key, $params) || null === $params[$key]) {
            return $default;
        }
        $value = filter_var($params[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $value ?? $default;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function array(array $params, string $key): array
    {
        $value = $params[$key] ?? null;
        if (\is_object($value)) {
            $value = json_decode((string) wp_json_encode($value), true);
        }

        return \is_array($value) ? $value : [];
    }

    /**
     * @param mixed $data
     */
    public static function ok($data, int $status = 200): \WP_REST_Response
    {
        return new \WP_REST_Response($data, $status);
    }

    /** @param array<string, mixed> $data */
    public static function error(string $code, string $message, int $status, array $data = []): \WP_Error
    {
        return new \WP_Error($code, $message, $data + ['status' => $status]);
    }
}
