<?php
declare(strict_types=1);

namespace Hendy;

use InvalidArgumentException;
use JsonException;

final class WebhookSecurity
{
    public static function verifySecret(string $expected, string $received): bool
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{32,256}\z/D', $expected)
            || !preg_match('/\A[A-Za-z0-9_-]{32,256}\z/D', $received)) {
            return false;
        }
        return hash_equals($expected, $received);
    }

    public static function verifyHmac(string $rawBody, string $signature, string $secret): bool
    {
        if ($rawBody === '' || strlen($secret) < 32
            || !preg_match('/\Asha256=[a-f0-9]{64}\z/D', $signature)) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $signature);
    }

    public static function readRawBody(int $maxBytes): string
    {
        if ($maxBytes < 1 || $maxBytes > 5_242_880) {
            throw new InvalidArgumentException('Giới hạn payload không hợp lệ.');
        }
        $stream = fopen('php://input', 'rb');
        if ($stream === false) {
            throw new InvalidArgumentException('Không thể đọc request body.');
        }
        try {
            $raw = stream_get_contents($stream, $maxBytes + 1);
        } finally {
            fclose($stream);
        }
        if (!is_string($raw) || strlen($raw) > $maxBytes) {
            throw new InvalidArgumentException('Request body vượt quá giới hạn.');
        }
        return $raw;
    }

    /** @return array<string, mixed> */
    public static function decodeJson(string $rawBody, int $maxBytes): array
    {
        if ($rawBody === '' || strlen($rawBody) > $maxBytes) {
            throw new InvalidArgumentException('JSON payload rỗng hoặc vượt giới hạn.');
        }
        try {
            $decoded = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('JSON payload không hợp lệ.');
        }
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('JSON payload phải là object hoặc array.');
        }
        return $decoded;
    }
}
