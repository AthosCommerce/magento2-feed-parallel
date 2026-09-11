<?php
/**
 * Copyright (C) 2025 AthosCommerce <https://athoscommerce.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 */

declare(strict_types=1);

namespace AthosCommerce\FeedParallel\Model\Storage;

class ShardAppender
{
    /**
     * Append shard file contents to the main feed file in page order.
     *
     * @param string $mainFilePath
     * @param string[] $shardFilePaths
     * @return void
     */
    public function appendShards(string $mainFilePath, array $shardFilePaths): void
    {
        $this->appendShardFiles($mainFilePath, $shardFilePaths, false);
    }

    /**
     * Append shard files while keeping only the first header row.
     *
     * @param string $mainFilePath
     * @param string[] $shardFilePaths
     * @return void
     */
    public function appendShardsWithSingleHeader(string $mainFilePath, array $shardFilePaths): void
    {
        $this->appendShardFiles($mainFilePath, $shardFilePaths, true);
    }

    /**
     * @param string $mainFilePath
     * @param string[] $shardFilePaths
     * @param bool $skipHeaderFromFollowingShards
     * @return void
     */
    private function appendShardFiles(
        string $mainFilePath,
        array $shardFilePaths,
        bool $skipHeaderFromFollowingShards
    ): void
    {
        $mainHandle = fopen($mainFilePath, 'ab');
        if ($mainHandle === false) {
            throw new \RuntimeException(sprintf('Unable to open main feed file: %s', $mainFilePath));
        }

        if (!flock($mainHandle, LOCK_EX)) {
            fclose($mainHandle);
            throw new \RuntimeException(sprintf('Unable to lock main feed file: %s', $mainFilePath));
        }

        try {
            $firstShardFilePath = $shardFilePaths[0] ?? null;

            foreach ($shardFilePaths as $shardFilePath) {
                if (!is_readable($shardFilePath)) {
                    throw new \RuntimeException(sprintf('Shard file is not readable: %s', $shardFilePath));
                }

                $shardHandle = fopen($shardFilePath, 'rb');
                if ($shardHandle === false) {
                    throw new \RuntimeException(sprintf('Unable to open shard file: %s', $shardFilePath));
                }

                try {
                    if ($skipHeaderFromFollowingShards && $firstShardFilePath !== null && $shardFilePath !== $firstShardFilePath) {
                        $headerLine = fgets($shardHandle);
                        if ($headerLine === false && !feof($shardHandle)) {
                            throw new \RuntimeException(sprintf('Unable to read shard header: %s', $shardFilePath));
                        }
                    }

                    while (!feof($shardHandle)) {
                        $chunk = fread($shardHandle, 1024 * 512);
                        if ($chunk === false) {
                            throw new \RuntimeException(sprintf('Unable to read shard file: %s', $shardFilePath));
                        }

                        if ($chunk !== '') {
                            fwrite($mainHandle, $chunk);
                        }
                    }
                } finally {
                    fclose($shardHandle);
                }
            }
        } finally {
            flock($mainHandle, LOCK_UN);
            fclose($mainHandle);
        }
    }
}
