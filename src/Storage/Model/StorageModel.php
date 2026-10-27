<?php

namespace BitApps\AiKit\Storage\Model;

use BitApps\WPDatabase\Connection;
use BitApps\WPDatabase\Model;

/**
 * Base of the kit's table models, on the Bit Apps ORM (`bitapps/wp-database`) that
 * every Bit plugin ships and prefixes with the kit.
 *
 * The ORM's Connection holds one plugin prefix for the whole plugin, and the kit
 * must never change it: the host plugin's own models read it. A kit model takes
 * its table prefix per instance instead (`for()`), from the PluginContext's
 * option prefix, so `{wp_prefix}{option_prefix}aikit_*` is the table whatever
 * the host set. Rows the ORM returns are clones of that instance, so they keep it.
 *
 * Rules the Bit plugins learned on this ORM (Bit Form CLAUDE.md): no automatic
 * timestamps (the kit writes UTC itself), no casts on JSON columns (decoded by
 * hand), no model events, and a failed query reads as an empty result, so writes
 * check Connection's `last_error`.
 */
abstract class StorageModel extends Model
{
    public $timestamps = false;

    /** The option prefix the next instance is built for (set and cleared by for()). */
    private static string $building = '';

    public function __construct($attributes = [])
    {
        if ('' !== self::$building) {
            $this->prefix = self::$building;
        }
        parent::__construct($attributes);
    }

    /**
     * A fresh query on this plugin's table: `Conversation::for($prefix)->where(…)->get()`.
     *
     * @return static
     */
    public static function for(string $optionPrefix)
    {
        self::$building = $optionPrefix;

        try {
            return new static();
        } finally {
            self::$building = '';
        }
    }

    /** Whether the last query on the connection failed. */
    public static function failed(): bool
    {
        return '' !== (string) Connection::prop('last_error');
    }

    /** The id of the row the last insert created. */
    public static function insertedId(): int
    {
        return (int) Connection::prop('insert_id');
    }

    /** @return null|array<mixed> */
    public static function decode($json): ?array
    {
        if (!\is_string($json) || '' === $json) {
            return null;
        }
        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : null;
    }

    /** A stored UTC datetime as a Unix timestamp; 0 when unreadable. */
    public static function timestamp($datetime): int
    {
        $time = \is_string($datetime) ? strtotime($datetime . ' UTC') : false;

        return false === $time ? 0 : $time;
    }
}
