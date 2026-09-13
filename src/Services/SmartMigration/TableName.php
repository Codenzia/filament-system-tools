<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Services\SmartMigration;

class TableName
{
    /**
     * Strip a leading schema/database prefix from a qualified table name
     * (e.g. "public.users" → "users").
     */
    public static function strip(string $table): string
    {
        return str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
    }
}
