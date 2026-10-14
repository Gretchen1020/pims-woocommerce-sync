<?php
declare(strict_types=1);

namespace Pims;

final class Config
{
    private static ?array $data = null;

    /** Read a value using dot notation, e.g. Config::get('db.host'). */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$data === null) {
            $file = dirname(__DIR__) . '/config/config.php';
            if (!is_file($file)) {
                throw new \RuntimeException(
                    'Missing config/config.php. Copy config/config.example.php and fill it in.'
                );
            }
            self::$data = require $file;
        }

        $value = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
