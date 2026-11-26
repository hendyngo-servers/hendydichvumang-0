<?php
declare(strict_types=1);

namespace Hendy;

final class MicrosoftStoreConnector extends BaseStoreConnector
{
    public function provider(): string
    {
        return 'microsoft_store';
    }

    protected function requiredKeys(): array
    {
        return ['client_id', 'tenant_id', 'client_secret_ref', 'product_id'];
    }
}
