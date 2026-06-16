<?php

namespace App\Support;

class Format
{
    public static function bytes(?int $bytes, int $precision = 1): string
    {
        $bytes = (int) $bytes;
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $power), $precision) . ' ' . $units[$power];
    }
}
