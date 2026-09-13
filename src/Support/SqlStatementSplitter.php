<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Support;

/**
 * Statement-aware SQL splitter. Tracks single/double-quote and backtick
 * state plus line and block comments so `;` characters inside quoted
 * literals do not split a statement mid-way (the naive explode(';') did,
 * corrupting any INSERT containing a semicolon).
 */
class SqlStatementSplitter
{
    /**
     * @return list<string>
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);

        $inSingle = false;   // '...'
        $inDouble = false;   // "..."
        $inBacktick = false; // `...`
        $inLineComment = false;  // -- ... \n
        $inBlockComment = false; // /* ... */

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                }

                continue;
            }

            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }

                continue;
            }

            if (! $inSingle && ! $inDouble && ! $inBacktick) {
                if ($char === '-' && $next === '-') {
                    $inLineComment = true;
                    $i++;

                    continue;
                }

                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i++;

                    continue;
                }
            }

            if ($char === "'" && ! $inDouble && ! $inBacktick) {
                // Handle escaped '' inside a single-quoted string.
                if ($inSingle && $next === "'") {
                    $current .= "''";
                    $i++;

                    continue;
                }
                $inSingle = ! $inSingle;
            } elseif ($char === '"' && ! $inSingle && ! $inBacktick) {
                $inDouble = ! $inDouble;
            } elseif ($char === '`' && ! $inSingle && ! $inDouble) {
                $inBacktick = ! $inBacktick;
            }

            if ($char === ';' && ! $inSingle && ! $inDouble && ! $inBacktick) {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}
