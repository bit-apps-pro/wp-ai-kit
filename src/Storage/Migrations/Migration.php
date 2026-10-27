<?php

namespace BitApps\AiKit\Storage\Migrations;

/**
 * One schema step on the kit's tables, in the Bit Apps ORM's Schema builder
 * (the way the Bit plugins write theirs). `$prefix` is the full table prefix,
 * `{wp_prefix}{option_prefix}`. `up()` must be safe to run again: Tables runs
 * the steps for a plugin whenever its schema stamp is out of date.
 */
interface Migration
{
    /** The Tables part this step belongs to (Tables::CONVERSATIONS or Tables::AUDIT). */
    public function part(): string;

    public function up(string $prefix): void;

    public function down(string $prefix): void;
}
