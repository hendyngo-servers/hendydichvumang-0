<?php
declare(strict_types=1);

namespace Hendy;

interface WebServiceContract
{
    /**
     * Handle one authenticated API request for a registered service.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function handle(string $method, string $action, array $payload, array $context): array;
}
