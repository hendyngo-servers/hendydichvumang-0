<?php
declare(strict_types=1);

namespace Hendy;

use RuntimeException;

final class WebServiceKernel
{
    /** @var array<string, class-string<WebServiceContract>> */
    private const SERVICES = [
        'system' => SystemWebService::class,
    ];

    public static function handles(string $path): bool
    {
        return str_starts_with($path, '/api/services/');
    }

    /**
     * Explicit allow-list router. New services must be registered in SERVICES;
     * uploaded files and user-controlled class names are never executed.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function dispatch(string $method, string $path, array $payload, array $context): array
    {
        if (!preg_match('#^/api/services/([a-z][a-z0-9-]{1,62})/([a-z][a-z0-9-]{1,62})$#D', $path, $match)) {
            throw new RuntimeException('Đường dẫn Web Service không hợp lệ.');
        }

        $serviceKey = $match[1];
        $action = $match[2];
        $serviceClass = self::SERVICES[$serviceKey] ?? null;
        if ($serviceClass === null || !class_exists($serviceClass)) {
            throw new RuntimeException('Web Service chưa được đăng ký.');
        }

        if (!in_array(strtoupper($method), ['GET', 'POST', 'PATCH', 'DELETE'], true)) {
            throw new RuntimeException('Phương thức HTTP không được hỗ trợ.');
        }

        return $serviceClass::handle(strtoupper($method), $action, $payload, $context);
    }

    /** @return list<string> */
    public static function registeredServices(): array
    {
        return array_keys(self::SERVICES);
    }
}
