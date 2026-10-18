<?php

namespace BitApps\AiKit\Security;

/**
 * Keeps credentials out of everything a tool returns.
 *
 * Plugin data mixes settings with secrets (integration API keys, OAuth tokens,
 * SMTP passwords, webhook URLs carrying tokens). ToolRunner passes every result
 * through redact(), so a tool cannot forget to. Matching is by key name and
 * deliberately broad: a wrongly redacted setting costs the model some context,
 * a missed secret leaks a credential to a third party. Plugins add names through
 * the `bitapps_ai_secret_keys` filter rather than narrowing the pattern.
 */
final class SecretRedactor
{
    public const REDACTED = '[redacted]';

    public const FILTER = 'bitapps_ai_secret_keys';

    private const SECRET_PATTERN = '/pass(word|wd|phrase)?|secret|token|api[_-]?key|apikey|auth(?!or)|bearer|credential|private[_-]?key|signature|cookie|session[_-]?id|salt|nonce|client[_-]?id|license[_-]?key|webhook[_-]?secret|(?<!field|form|meta|map|row|entry|field_|form_|meta_)_key$/i';

    private const URL_PATTERN = '/url|uri|endpoint|hook|callback|redirect/i';

    private const MAX_DEPTH = 12;

    /** @var null|string[] */
    private ?array $extraKeys = null;

    public function __construct(private string $pluginSlug = '') {}

    /**
     * @param mixed $key array keys arrive as int or string; only strings can name a secret
     */
    public function isSecretKey($key): bool
    {
        if (!\is_string($key) || '' === $key) {
            return false;
        }

        if (1 === preg_match(self::SECRET_PATTERN, $key)) {
            return true;
        }

        return \in_array(strtolower($key), $this->extraKeys(), true);
    }

    /**
     * A copy with every credential replaced by REDACTED (empty values kept, so a
     * model can still tell "not configured") and every URL reduced to its host.
     *
     * @param mixed $data
     *
     * @return mixed
     */
    public function redact($data)
    {
        return $this->redactValue($data, 0);
    }

    /**
     * Whether $data holds a non-empty credential — "connected" without showing what with.
     *
     * @param mixed $data
     */
    public function hasCredentials($data): bool
    {
        return $this->findCredential($data, 0);
    }

    public static function maskUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || empty($parts['host'])) {
            return self::REDACTED;
        }

        return (!empty($parts['scheme']) ? $parts['scheme'] . '://' : '') . $parts['host'];
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private function redactValue($value, int $depth)
    {
        if ($depth > self::MAX_DEPTH) {
            return self::REDACTED;
        }

        if (\is_object($value)) {
            $value = $value instanceof \JsonSerializable ? $value->jsonSerialize() : get_object_vars($value);
        }

        if (!\is_array($value)) {
            return $value;
        }

        $clean = [];
        foreach ($value as $key => $item) {
            if ($this->isSecretKey($key)) {
                $clean[$key] = self::isEmpty($item) ? $item : self::REDACTED;

                continue;
            }

            if (\is_string($key) && \is_string($item) && 1 === preg_match(self::URL_PATTERN, $key) && str_contains($item, '://')) {
                $clean[$key] = self::maskUrl($item);

                continue;
            }

            $clean[$key] = $this->redactValue($item, $depth + 1);
        }

        return $clean;
    }

    /** @param mixed $value */
    private function findCredential($value, int $depth): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return false;
        }

        if (\is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!\is_array($value)) {
            return false;
        }

        foreach ($value as $key => $item) {
            if ($this->isSecretKey($key) && !self::isEmpty($item)) {
                return true;
            }

            if ($this->findCredential($item, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    private function extraKeys(): array
    {
        if (null === $this->extraKeys) {
            $extra = \function_exists('apply_filters') ? apply_filters(self::FILTER, [], $this->pluginSlug) : [];
            $this->extraKeys = \is_array($extra) ? array_map('strtolower', array_filter($extra, 'is_string')) : [];
        }

        return $this->extraKeys;
    }

    /** @param mixed $value */
    private static function isEmpty($value): bool
    {
        return null === $value || '' === $value || [] === $value || false === $value;
    }
}
