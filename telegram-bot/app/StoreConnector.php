<?php
declare(strict_types=1);

namespace Hendy;

interface StoreConnector
{
    public function provider(): string;

    public function validateConfiguration(array $config): void;

    public function capabilities(): array;
}
