<?php

namespace BitApps\AiKit\Security;

/**
 * Fixed-window counters for tool calls, token requests and assistant messages.
 *
 * With a persistent object cache the count is an atomic wp_cache_incr(); without
 * one it falls back to a transient read-modify-write, where two requests in the
 * same instant may both be admitted. That is accepted: the limiter bounds cost
 * and abuse, it is not an authorization check.
 */
final class RateLimiter
{
    private const CACHE_GROUP = 'bitapps_ai_rate_limit';

    /**
     * @param string $prefix the plugin's option prefix, so plugins never share counters
     */
    public function __construct(private string $prefix) {}

    /**
     * Count one attempt against $key.
     *
     * @param int $limit  attempts per window; 0 or less = unlimited
     * @param int $window seconds
     *
     * @return array{allowed: bool, remaining: int, retry_after: int, count: int}
     */
    public function attempt(string $key, int $limit, int $window = 60): array
    {
        $window = max(1, $window);
        $now = time();
        $bucket = intdiv($now, $window);

        if ($limit <= 0) {
            return ['allowed' => true, 'remaining' => PHP_INT_MAX, 'retry_after' => 0, 'count' => 0];
        }

        $count = $this->increment($this->prefix . 'rl_' . md5($key) . '_' . $bucket, $window);
        $allowed = $count <= $limit;

        return [
            'allowed'     => $allowed,
            'remaining'   => max(0, $limit - $count),
            'retry_after' => $allowed ? 0 : (($bucket + 1) * $window) - $now,
            'count'       => $count,
        ];
    }

    private function increment(string $storageKey, int $window): int
    {
        if (\function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            wp_cache_add($storageKey, 0, self::CACHE_GROUP, $window);
            $count = wp_cache_incr($storageKey, 1, self::CACHE_GROUP);
            if (false !== $count) {
                return (int) $count;
            }
        }

        $count = (int) get_transient($storageKey) + 1;
        set_transient($storageKey, $count, $window);

        return $count;
    }
}
