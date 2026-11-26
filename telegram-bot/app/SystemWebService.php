<?php
declare(strict_types=1);

namespace Hendy;

use RuntimeException;

final class SystemWebService implements WebServiceContract
{
    public static function handle(string $method, string $action, array $payload, array $context): array
    {
        if ($method !== 'GET') {
            throw new RuntimeException('System Web Service hiện chỉ hỗ trợ GET.');
        }

        return match ($action) {
            'health' => [
                'service' => 'hendy-system-web-service',
                'status' => Database::check() ? 'ok' : 'degraded',
                'database' => Database::check() ? 'connected' : 'unavailable',
                'botKey' => (string) ($context['botKey'] ?? 'primary'),
                'timestamp' => gmdate(DATE_ATOM),
            ],
            'capabilities' => [
                'service' => 'hendy-web-service-kernel',
                'registeredServices' => WebServiceKernel::registeredServices(),
                'extensionPoint' => 'app/WebServices + WebServiceKernel::SERVICES',
                'authenticated' => true,
            ],
            default => throw new RuntimeException('System Web Service không có action này.'),
        };
    }
}
