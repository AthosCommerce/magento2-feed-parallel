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

class CatalogShardWriter
{
    private const HEADER = [
        'uid',
        'sku',
        'parent_uid',
        'name',
        'price',
        'url',
        'imageUrl',
        'thumbnailImageUrl',
        'recordHash',
    ];

    /**
     * @var FileDriver
     */
    private $fileDriver;

    /**
     * @param FileDriver $fileDriver
     */
    public function __construct(FileDriver $fileDriver)
    {
        $this->fileDriver = $fileDriver;
    }

    /**
     * Append catalog rows from feed items to a TSV shard file (header written once).
     *
     * @param array $items
     * @param string $shardFilePath
     * @return int
     * @throws FileSystemException
     */
    public function appendItems(array $items, string $shardFilePath): int
    {
        $writeHeader = !$this->fileDriver->isFile($shardFilePath)
            || (int)($this->fileDriver->stat($shardFilePath)['size'] ?? 0) === 0;

        $handle = $this->fileDriver->fileOpen($shardFilePath, 'ab');

        try {
            if ($writeHeader) {
                $this->writeRow($handle, self::HEADER);
            }

            $catalogRows = $this->extractCatalogRows($items);
            foreach ($catalogRows as $row) {
                $this->writeRow($handle, [
                    $row['uid'],
                    $row['sku'],
                    $row['parent_uid'],
                    $row['name'],
                    $row['price'],
                    $row['url'],
                    $row['imageUrl'],
                    $row['thumbnailImageUrl'],
                    $row['recordHash'],
                ]);
            }
        } finally {
            $this->fileDriver->fileClose($handle);
        }

        return count($catalogRows);
    }

    /**
     * Write one tab-separated row the same way the core catalog export does (File\Write::writeCsv()).
     *
     * @param resource $handle
     * @param array $row
     * @return void
     * @throws FileSystemException
     */
    private function writeRow($handle, array $row): void
    {
        $this->fileDriver->filePutCsv($handle, $row, "\t");
    }

    /**
     * Collect unique catalog rows (keyed by record hash) from the feed items.
     *
     * @param array $items
     * @return array<string, array<string, mixed>>
     */
    private function extractCatalogRows(array $items): array
    {
        $catalogRows = [];

        foreach ($items as $item) {
            if (empty($item['__catalog']) || !is_array($item['__catalog'])) {
                continue;
            }

            foreach ($item['__catalog'] as $row) {
                $recordHash = (string)($row['recordHash'] ?? '');
                if (isset($catalogRows[$recordHash])) {
                    continue;
                }

                $catalogRows[$recordHash] = [
                    'uid' => (string)($row['uid'] ?? ''),
                    'sku' => (string)($row['sku'] ?? ''),
                    'parent_uid' => (string)($row['parent_uid'] ?? ''),
                    'name' => (string)($row['name'] ?? ''),
                    'price' => $row['price'] ?? '',
                    'url' => (string)($row['url'] ?? ''),
                    'imageUrl' => (string)($row['imageUrl'] ?? ''),
                    'thumbnailImageUrl' => (string)($row['thumbnailImageUrl'] ?? ''),
                    'recordHash' => $recordHash,
                ];
            }
        }

        return $catalogRows;
    }
}
