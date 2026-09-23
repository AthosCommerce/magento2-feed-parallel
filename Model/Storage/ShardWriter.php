<?php
/**
 * Copyright (C) 2025 AthosCommerce <https://athoscommerce.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 */

declare(strict_types=1);

namespace AthosCommerce\FeedParallel\Model\Storage;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;

class ShardWriter
{
    /**
     * @var JsonSerializer
     */
    private $jsonSerializer;

    /**
     * @var FileDriver
     */
    private $fileDriver;

    /**
     * @param JsonSerializer $jsonSerializer
     * @param FileDriver $fileDriver
     */
    public function __construct(JsonSerializer $jsonSerializer, FileDriver $fileDriver)
    {
        $this->jsonSerializer = $jsonSerializer;
        $this->fileDriver = $fileDriver;
    }

    /**
     * Append feed items to a JSONL shard file, one item per line.
     *
     * @param array $items
     * @param string $shardFilePath
     * @return void
     * @throws FileSystemException
     */
    public function appendItems(array $items, string $shardFilePath): void
    {
        $handle = $this->fileDriver->fileOpen($shardFilePath, 'ab');

        try {
            foreach ($items as $item) {
                if (!empty($item['__catalog']) && is_array($item['__catalog'])) {
                    unset($item['__catalog']);
                }

                $this->fileDriver->fileWrite(
                    $handle,
                    $this->jsonSerializer->serialize($item) . PHP_EOL
                );
            }
        } finally {
            $this->fileDriver->fileClose($handle);
        }
    }

    /**
     * Write the worker meta file with product and catalog row counts.
     *
     * @param int $productCount
     * @param string $metaFilePath
     * @param int $catalogRowCount
     * @return void
     * @throws FileSystemException
     */
    public function writeMeta(int $productCount, string $metaFilePath, int $catalogRowCount = 0): void
    {
        $payload = $this->jsonSerializer->serialize([
            'productCount' => $productCount,
            'catalogRowCount' => $catalogRowCount,
        ]);
        $this->fileDriver->filePutContents($metaFilePath, $payload);
    }
}
