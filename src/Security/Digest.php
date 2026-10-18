<?php

namespace BitApps\AiKit\Security;

/**
 * Stable hashes for audit rows and confirmation tokens: key order never changes
 * the digest, and values are never stored, only their hash.
 */
final class Digest
{
    /** @param mixed $value */
    public static function of($value): ?string
    {
        if (null === $value || [] === $value) {
            return null;
        }

        $encoded = wp_json_encode(self::sortKeys($value));

        return \is_string($encoded) ? hash('sha256', $encoded) : null;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private static function sortKeys($value)
    {
        if (\is_object($value)) {
            $value = $value instanceof \JsonSerializable ? $value->jsonSerialize() : get_object_vars($value);
        }

        if (!\is_array($value)) {
            return $value;
        }

        ksort($value);

        return array_map([self::class, 'sortKeys'], $value);
    }
}
