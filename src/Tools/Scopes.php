<?php

namespace BitApps\AiKit\Tools;

/**
 * The three generic access levels every plugin's tools fall into.
 *
 * A tool may name its own scope (Bit PI: `flow:write`); when it does not, the
 * scope follows from its annotations: read-only tools need `read`, destructive
 * ones `destructive`, everything else `write`. The levels nest — a grant of
 * `destructive` covers `write` and `read`, `write` covers `read` — which is the
 * hierarchy the MCP authorization spec asks servers to honour. Plugin-specific
 * scope strings do not nest and are compared literally after the plugin's scope
 * map has turned legacy names into canonical ones.
 */
final class Scopes
{
    public const READ = 'read';

    public const WRITE = 'write';

    public const DESTRUCTIVE = 'destructive';

    private const IMPLIES = [
        self::DESTRUCTIVE => [self::DESTRUCTIVE, self::WRITE, self::READ],
        self::WRITE       => [self::WRITE, self::READ],
        self::READ        => [self::READ],
    ];

    /** The access levels in increasing order. */
    public static function levels(): array
    {
        return [self::READ, self::WRITE, self::DESTRUCTIVE];
    }

    /**
     * Every scope a set of granted scopes covers, hierarchy applied.
     *
     * @param string[] $granted
     *
     * @return string[]
     */
    public static function expand(array $granted): array
    {
        $covered = [];
        foreach ($granted as $scope) {
            if (!\is_string($scope) || '' === $scope) {
                continue;
            }
            foreach (self::IMPLIES[$scope] ?? [$scope] as $implied) {
                $covered[$implied] = true;
            }
        }

        return array_keys($covered);
    }

    /**
     * Whether $granted (already canonical) satisfies $required.
     *
     * @param string[] $granted
     */
    public static function satisfies(array $granted, string $required): bool
    {
        return \in_array($required, self::expand($granted), true);
    }

    /** Whether $level is at or below $ceiling in read < write < destructive. */
    public static function withinLevel(string $level, string $ceiling): bool
    {
        $order = array_flip(self::levels());

        if (!isset($order[$level], $order[$ceiling])) {
            return false;
        }

        return $order[$level] <= $order[$ceiling];
    }
}
