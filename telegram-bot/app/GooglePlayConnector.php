<?php
declare(strict_types=1);

namespace Hendy;

final class GooglePlayConnector extends BaseStoreConnector
{
    public function provider(): string
    {
        return 'google_play';
    }

    protected function requiredKeys(): array
    {
        return ['service_account_secret_ref', 'package_name'];
    }
}
