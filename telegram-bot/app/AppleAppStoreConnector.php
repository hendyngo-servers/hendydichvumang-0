<?php
declare(strict_types=1);

namespace Hendy;

final class AppleAppStoreConnector extends BaseStoreConnector
{
    public function provider(): string
    {
        return 'app_store_connect';
    }

    protected function requiredKeys(): array
    {
        return ['issuer_id', 'key_id', 'private_key_secret_ref'];
    }
}
