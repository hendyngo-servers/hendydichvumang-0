<?php
declare(strict_types=1);

namespace Hendy;

final class Env
{
    private static bool $loaded = false;

    public static function load(string $file): void
    {
        if (self::$loaded || !is_file($file) || !is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (!preg_match('/\A[A-Z][A-Z0-9_]{0,127}\z/D', $key) || getenv($key) !== false) {
                continue;
            }

            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if ($first === '"' && $last === '"') {
                    $value = stripcslashes(substr($value, 1, -1));
                } elseif ($first === "'" && $last === "'") {
                    $value = substr($value, 1, -1);
                }
            } else {
                $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (!preg_match('/\A[A-Z][A-Z0-9_]{0,127}\z/D', $key)) {
            return $default;
        }
        $value = getenv($key);
        return $value === false || $value === '' ? $default : (string) $value;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        if ($value === null || !preg_match('/\A-?\d{1,12}\z/D', $value)) {
            return $default;
        }
        return (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
