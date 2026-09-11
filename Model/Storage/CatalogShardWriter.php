<?php
/**
 * Copyright (C) 2025 AthosCommerce <https://athoscommerce.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 */

declare(strict_types=1);

namespace AthosCommerce\FeedParallel\Model\Storage;

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
     * @param array<int, array<string, mixed>> $items
     * @param string $shardFilePath
     * @return int
     */
    public function appendItems(array $items, string $shardFilePath): int
    {
        clearstatcache(true, $shardFilePath);
        $writeHeader = !is_file($shardFilePath) || filesize($shardFilePath) === 0;

        $handle = fopen($shardFilePath, 'ab');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Unable to open catalog shard file: %s', $shardFilePath));
        }

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
            fclose($handle);
        }

        return count($catalogRows);
    }

    /**
     * @param resource $handle
     * @param array<int, scalar|null> $row
     * @return void
     */
    private function writeRow($handle, array $row): void
    {
        if (fputcsv($handle, $row, "\t") === false) {
            throw new \RuntimeException('Unable to write catalog shard row.');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items
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
