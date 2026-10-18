<?php

namespace BitApps\AiKit\Security;

/**
 * Masks personal data in tool results unless the grant allows it.
 *
 * The plugin knows which values are personal (Bit Form: field types such as
 * email, phone, name, address, file uploads; Bit CRM: contact columns), so it
 * supplies a classifier: `callable(string $key, mixed $value, array $path, array $parent): ?string`
 * (`$parent` is the array holding the value, so `['type' => 'email', 'value' => …]` can be
 * classified by its sibling `type`)
 * returning a kind (`email`, `phone`, `name`, `address`, `file`, `ip`, `text`,
 * `drop`) or null for non-personal values. The masker only applies the masks.
 */
final class PiiMasker
{
    public const EMAIL = 'email';

    public const PHONE = 'phone';

    public const NAME = 'name';

    public const ADDRESS = 'address';

    public const FILE = 'file';

    public const IP = 'ip';

    public const TEXT = 'text';

    /** Remove the value entirely (passwords, card numbers, signatures). */
    public const DROP = 'drop';

    private const MAX_DEPTH = 12;

    /** @var callable */
    private $classifier;

    public function __construct(callable $classifier)
    {
        $this->classifier = $classifier;
    }

    /**
     * Mask one value of a known kind.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public static function maskValue($value, string $kind)
    {
        if (self::DROP === $kind) {
            return null;
        }

        if (\is_array($value)) {
            return array_map(static fn ($item) => self::maskValue($item, $kind), $value);
        }

        if (!\is_string($value) && !is_numeric($value)) {
            return $value;
        }

        $value = (string) $value;
        if ('' === $value) {
            return $value;
        }

        // Mask inside an untrusted-content fence and keep the markers, so a tool may
        // fence first and the masking after it neither leaks nor breaks the fence.
        $open = UntrustedContent::OPEN;
        $close = UntrustedContent::CLOSE;
        if (str_starts_with($value, $open) && str_ends_with($value, $close) && \strlen($value) >= \strlen($open . $close)) {
            $inner = substr($value, \strlen($open), -\strlen($close));

            return $open . self::maskValue($inner, $kind) . $close;
        }

        switch ($kind) {
            case self::EMAIL:
                $at = strrpos($value, '@');
                if (false === $at) {
                    return self::initial($value);
                }

                return self::initial(substr($value, 0, $at)) . '@' . substr($value, $at + 1);

            case self::PHONE:
                $digits = preg_replace('/\D+/', '', $value);

                return '***' . substr((string) $digits, -2);

            case self::IP:
                return str_contains($value, ':') ? preg_replace('/:[^:]*:[^:]*$/', ':****:****', $value) : preg_replace('/\.\d+\.\d+$/', '.*.*', $value);

            case self::FILE:
                $base = basename((string) parse_url($value, PHP_URL_PATH));
                $ext = pathinfo($base, PATHINFO_EXTENSION);

                return '[file' . ('' !== $ext ? ' .' . strtolower($ext) : '') . ']';

            case self::NAME:
            case self::ADDRESS:
            case self::TEXT:
            default:
                return self::initial($value);
        }
    }

    /**
     * A masked copy of $data.
     *
     * @param mixed $data
     *
     * @return mixed
     */
    public function mask($data)
    {
        return $this->maskNode($data, [], 0);
    }

    /**
     * @param mixed                  $value
     * @param array<int, int|string> $path
     *
     * @return mixed
     */
    private function maskNode($value, array $path, int $depth)
    {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }

        if (\is_object($value)) {
            $value = $value instanceof \JsonSerializable ? $value->jsonSerialize() : get_object_vars($value);
        }

        if (!\is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            $itemPath = array_merge($path, [$key]);
            // List items (int keys) are classified too, by value: `'to' => ['jane@example.com']`.
            $kind = ($this->classifier)(\is_string($key) ? $key : '', $item, $itemPath, $value);

            if (\is_string($kind) && '' !== $kind) {
                if (self::DROP === $kind) {
                    continue;
                }
                $out[$key] = self::maskValue($item, $kind);

                continue;
            }

            $out[$key] = $this->maskNode($item, $itemPath, $depth + 1);
        }

        return $out;
    }

    /** `jane doe` → `j*** d***`; keeps the shape without the content. */
    private static function initial(string $value): string
    {
        return (string) preg_replace_callback('/\S+/u', static fn (array $m): string => mb_substr($m[0], 0, 1) . '***', $value);
    }
}
