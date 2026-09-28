<?php

declare(strict_types=1);

namespace FarosCMS;

/** Small display formatters shared by admin services. */
final class Format
{
    public static function bytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unitIndex = 0;
        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }
        return number_format($value, 1) . ' ' . $units[$unitIndex];
    }
}
