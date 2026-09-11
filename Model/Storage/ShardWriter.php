<?php
/**
 * Copyright (C) 2025 AthosCommerce <https://athoscommerce.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 */

declare(strict_types=1);

namespace AthosCommerce\FeedParallel\Model\Storage;

use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;

class ShardWriter
{
    /**
     * @var JsonSerializer
     */
    private $jsonSerializer;

    /**
     * @param JsonSerializer $jsonSerializer
     */
    public function __construct(JsonSerializer $jsonSerializer)
    {
        $this->jsonSerializer = $jsonSerializer;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param string $shardFilePath
     * @return void
     */
    public function appendItems(array $items, string $shardFilePath): void
    {
        $handle = fopen($shardFilePath, 'ab');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Unable to open shard file: %s', $shardFilePath));
        }

        try {
            foreach ($items as $item) {
                if (!empty($item['__catalog']) && is_array($item['__catalog'])) {
                    unset($item['__catalog']);
                }

                fwrite(
                    $handle,
                    $this->jsonSerializer->serialize($item) . PHP_EOL
                );
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param int $productCount
     * @param string $metaFilePath
     * @param int $catalogRowCount
     * @return void
     */
    public function writeMeta(int $productCount, string $metaFilePath, int $catalogRowCount = 0): void
    {
        $payload = $this->jsonSerializer->serialize([
            'productCount' => $productCount,
            'catalogRowCount' => $catalogRowCount,
        ]);
        if (file_put_contents($metaFilePath, $payload) === false) {
            throw new \RuntimeException(sprintf('Unable to write shard meta file: %s', $metaFilePath));
        }
    }
}
