<?php
declare(strict_types=1);

namespace Hendy;

use RuntimeException;

abstract class BaseStoreConnector implements StoreConnector
{
    public function validateConfiguration(array $config): void
    {
        foreach ($this->requiredKeys() as $key) {
            if (!isset($config[$key]) || !is_string($config[$key]) || trim($config[$key]) === '') {
                throw new RuntimeException('Thiếu cấu hình nhà phát hành: ' . $key);
            }
        }
    }

    abstract protected function requiredKeys(): array;

    public function capabilities(): array
    {
        return [
            'metadata_read' => true,
            'upload_binary' => false,
            'submit_for_review' => false,
            'publish' => false,
        ];
    }
}
