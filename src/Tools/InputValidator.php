<?php

namespace BitApps\AiKit\Tools;

/**
 * Validates tool arguments against the tool's JSON Schema and coerces them.
 *
 * Inside WordPress it uses core's rest_validate_value_from_schema() and
 * rest_sanitize_value_from_schema() (available since 4.7, so on every supported
 * version); `"12"` for an integer property comes back as `12`. Outside WordPress
 * (unit tests, CLI tooling) a small subset runs: required keys, primitive types,
 * enums, and the same coercions.
 */
final class InputValidator
{
    /**
     * @param array<string, mixed> $schema
     * @param mixed                $args
     *
     * @return array{valid: bool, args: array<string, mixed>, error: ?string}
     */
    public static function validate(array $schema, $args): array
    {
        if (\is_object($args)) {
            $args = json_decode((string) wp_json_encode($args), true);
        }

        $args ??= [];

        if (!\is_array($args)) {
            return ['valid' => false, 'args' => [], 'error' => 'Tool arguments must be an object.'];
        }

        $schema['type'] = 'object';
        if (empty($schema['properties'])) {
            $schema['properties'] = [];
        }

        if (\function_exists('rest_validate_value_from_schema') && \function_exists('rest_sanitize_value_from_schema')) {
            $valid = rest_validate_value_from_schema($args, $schema, 'arguments');
            if (\function_exists('is_wp_error') && is_wp_error($valid)) {
                return ['valid' => false, 'args' => [], 'error' => $valid->get_error_message()];
            }

            $clean = rest_sanitize_value_from_schema($args, $schema, 'arguments');
            if (\function_exists('is_wp_error') && is_wp_error($clean)) {
                return ['valid' => false, 'args' => [], 'error' => $clean->get_error_message()];
            }

            return ['valid' => true, 'args' => \is_array($clean) ? $clean : [], 'error' => null];
        }

        return self::fallback($schema, $args);
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $args
     *
     * @return array{valid: bool, args: array<string, mixed>, error: ?string}
     */
    private static function fallback(array $schema, array $args): array
    {
        foreach ((array) ($schema['required'] ?? []) as $key) {
            if (!\array_key_exists($key, $args) || '' === $args[$key] || null === $args[$key]) {
                return ['valid' => false, 'args' => [], 'error' => "arguments[{$key}] is a required property of arguments."];
            }
        }

        foreach ((array) $schema['properties'] as $key => $property) {
            if (!\array_key_exists($key, $args) || !\is_array($property)) {
                continue;
            }

            $type = $property['type'] ?? null;
            $value = self::coerce($args[$key], $type);

            if (null !== $type && !self::matches($value, $type)) {
                $label = \is_array($type) ? implode(',', $type) : (string) $type;

                return ['valid' => false, 'args' => [], 'error' => "arguments[{$key}] is not of type {$label}."];
            }

            if (isset($property['enum']) && \is_array($property['enum']) && !\in_array($value, $property['enum'], true)) {
                return ['valid' => false, 'args' => [], 'error' => "arguments[{$key}] is not one of " . implode(', ', array_map('strval', $property['enum'])) . '.'];
            }

            $args[$key] = $value;
        }

        return ['valid' => true, 'args' => $args, 'error' => null];
    }

    /**
     * @param mixed                $value
     * @param null|string|string[] $type
     *
     * @return mixed
     */
    private static function coerce($value, $type)
    {
        $types = (array) $type;

        if (\in_array('integer', $types, true) && \is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        if (\in_array('number', $types, true) && \is_string($value) && is_numeric($value)) {
            return $value + 0;
        }

        if (\in_array('boolean', $types, true) && \is_string($value) && \in_array(strtolower($value), ['true', 'false', '1', '0'], true)) {
            return \in_array(strtolower($value), ['true', '1'], true);
        }

        return $value;
    }

    /**
     * @param mixed           $value
     * @param string|string[] $type
     */
    private static function matches($value, $type): bool
    {
        foreach ((array) $type as $one) {
            switch ($one) {
                case 'string':
                    if (\is_string($value)) {
                        return true;
                    }

                    break;

                case 'integer':
                    if (\is_int($value)) {
                        return true;
                    }

                    break;

                case 'number':
                    if (\is_int($value) || \is_float($value)) {
                        return true;
                    }

                    break;

                case 'boolean':
                    if (\is_bool($value)) {
                        return true;
                    }

                    break;

                case 'array':
                    if (\is_array($value) && array_values($value) === $value) {
                        return true;
                    }

                    break;

                case 'object':
                    if (\is_array($value) || \is_object($value)) {
                        return true;
                    }

                    break;

                case 'null':
                    if (null === $value) {
                        return true;
                    }

                    break;

                default:
                    return true;
            }
        }

        return false;
    }
}
