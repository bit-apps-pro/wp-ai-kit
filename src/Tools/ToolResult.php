<?php

namespace BitApps\AiKit\Tools;

/**
 * A tool's answer, convertible to the MCP `tools/call` result.
 *
 * Handlers may return a ToolResult, a WP_Error, an MCP result array
 * (`['content' => [...], 'isError' => bool]`, Bit CRM's shape) or any
 * JSON-serialisable value; from() turns each into a ToolResult.
 */
final class ToolResult
{
    /** @var array<string, mixed> transport hints, never sent to the client */
    private array $meta = [];

    /**
     * @param null|array<int|string, mixed>         $structured
     * @param null|array<int, array<string, mixed>> $content
     */
    private function __construct(private string $text, private $structured = null, private bool $isError = false, private ?string $errorCode = null, private ?array $content = null) {}

    public static function text(string $text): self
    {
        return new self($text);
    }

    /**
     * Structured data; the text block carries the same JSON unless $summary is given.
     *
     * @param array<int|string, mixed> $data
     */
    public static function json(array $data, ?string $summary = null): self
    {
        $text = $summary ?? (string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new self($text, $data);
    }

    public static function error(string $message, string $code = 'tool_error'): self
    {
        return new self($message, null, true, $code);
    }

    /**
     * Normalise whatever a handler returned.
     *
     * @param mixed $value
     */
    public static function from($value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value instanceof \WP_Error) {
            $code = (string) $value->get_error_code();

            return self::error($value->get_error_message() ?: 'The tool failed.', '' !== $code ? $code : 'tool_error');
        }

        if (\is_array($value) && isset($value['content']) && \is_array($value['content'])) {
            $text = '';
            foreach ($value['content'] as $block) {
                if (\is_array($block) && 'text' === ($block['type'] ?? null) && isset($block['text'])) {
                    $text .= ('' === $text ? '' : "\n") . (string) $block['text'];
                }
            }

            $structured = isset($value['structuredContent']) && \is_array($value['structuredContent']) ? $value['structuredContent'] : null;

            return new self($text, $structured, !empty($value['isError']), !empty($value['isError']) ? 'tool_error' : null, array_values($value['content']));
        }

        if (\is_string($value)) {
            return self::text($value);
        }

        if (null === $value || \is_bool($value) || is_numeric($value)) {
            return self::json(['result' => $value]);
        }

        if (\is_array($value)) {
            return self::json($value);
        }

        $plain = $value instanceof \Traversable ? self::rows($value) : self::toPlain($value);

        return self::json(\is_array($plain) ? $plain : ['result' => $plain]);
    }

    /**
     * A list of plain rows from whatever a query returned: an array, a
     * collection (`toArray()`), a single model, a Traversable or a
     * JsonSerializable. Lets tools pass ORM results without the kit depending on
     * any ORM.
     *
     * @param mixed $result
     *
     * @return list<mixed>
     */
    public static function rows($result): array
    {
        if (\is_array($result)) {
            return array_values($result);
        }

        if ($result instanceof \Traversable) {
            $rows = [];
            foreach ($result as $row) {
                $rows[] = self::toPlain($row);
            }

            return $rows;
        }

        if (\is_object($result) && method_exists($result, 'toArray')) {
            $array = $result->toArray();

            if (\is_array($array) && array_values($array) === $array && (empty($array) || \is_array($array[0]) || \is_object($array[0]))) {
                return array_map([self::class, 'toPlain'], $array);
            }

            return [$array];
        }

        if ($result instanceof \JsonSerializable || $result instanceof \stdClass) {
            return [self::toPlain($result)];
        }

        return [];
    }

    /** @param mixed $value */
    public function withMeta(string $key, $value): self
    {
        $copy = clone $this;
        $copy->meta[$key] = $value;

        return $copy;
    }

    /** @return mixed */
    public function meta(string $key)
    {
        return $this->meta[$key] ?? null;
    }

    public function isError(): bool
    {
        return $this->isError;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function textContent(): string
    {
        return $this->text;
    }

    /** @return null|array<int|string, mixed> */
    public function structured()
    {
        return $this->structured;
    }

    /**
     * A copy carrying only the given (redacted, masked) data: the text becomes
     * its JSON and any original content blocks are dropped, because a summary or
     * a raw block written from the unmasked data would leak what masking removed.
     *
     * @param array<int|string, mixed> $structured
     */
    public function withStructured(array $structured): self
    {
        return new self((string) wp_json_encode($structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $structured, $this->isError, $this->errorCode, null);
    }

    public function withText(string $text): self
    {
        return new self($text, $this->structured, $this->isError, $this->errorCode, null);
    }

    /** @return array<string, mixed> MCP `tools/call` result */
    public function toMcp(): array
    {
        $content = $this->content ?? [['type' => 'text', 'text' => $this->text]];

        $result = [
            'content' => $content,
            'isError' => $this->isError,
        ];

        if (null !== $this->structured && !$this->isError) {
            $result['structuredContent'] = $this->isList($this->structured) ? ['items' => $this->structured] : $this->structured;
        }

        return $result;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private static function toPlain($value)
    {
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        } elseif (\is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        } elseif (\is_object($value)) {
            $value = get_object_vars($value);
        }

        if (\is_array($value)) {
            return array_map([self::class, 'toPlain'], $value);
        }

        return $value;
    }

    /** @param array<int|string, mixed> $value */
    private function isList(array $value): bool
    {
        return [] === $value || array_keys($value) === range(0, \count($value) - 1);
    }
}
