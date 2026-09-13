<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Support;

class Bytes
{
    /**
     * Format a byte count as a human-readable string (e.g. "1.50 MB").
     */
    public static function format(int $bytes, int $precision = 2): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;

        for (; $value > 1024 && $i < count($units) - 1; $i++) {
            $value /= 1024;
        }

        return round($value, $precision).' '.$units[$i];
    }
}
