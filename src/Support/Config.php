<?php

declare(strict_types=1);

namespace App\Support;

final class Config
{
    private array $data;

    public function __construct()
    {
        $dist = dirname(__DIR__, 2) . '/config/app.php.dist';
        $local = dirname(__DIR__, 2) . '/config/app.local.php';
        $base = require $dist;
        $override = is_file($local) ? require $local : [];
        $this->data = self::mergeRecursive($base, $override);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->data;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private static function mergeRecursive(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::mergeRecursive($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
