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

class ShardAppender
{
    private const READ_CHUNK_SIZE = 1024 * 512;

    private const HEADER_MAX_LENGTH = 1024 * 1024;

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
     * Append shard file contents to the main feed file in page order.
     *
     * @param string $mainFilePath
     * @param string[] $shardFilePaths
     * @return void
     * @throws FileSystemException
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
     * @throws FileSystemException
     */
    public function appendShardsWithSingleHeader(string $mainFilePath, array $shardFilePaths): void
    {
        $this->appendShardFiles($mainFilePath, $shardFilePaths, true);
    }

    /**
     * Append shard files to the main file under an exclusive lock.
     *
     * @param string $mainFilePath
     * @param string[] $shardFilePaths
     * @param bool $skipHeaderFromFollowingShards
     * @return void
     * @throws FileSystemException
     */
    private function appendShardFiles(
        string $mainFilePath,
        array $shardFilePaths,
        bool $skipHeaderFromFollowingShards
    ): void {
        $mainHandle = $this->fileDriver->fileOpen($mainFilePath, 'ab');

        try {
            $this->fileDriver->fileLock($mainHandle, LOCK_EX);
        } catch (FileSystemException $exception) {
            $this->fileDriver->fileClose($mainHandle);
            throw $exception;
        }

        try {
            $firstShardFilePath = $shardFilePaths[0] ?? null;

            foreach ($shardFilePaths as $shardFilePath) {
                $skipHeader = $skipHeaderFromFollowingShards
                    && $firstShardFilePath !== null
                    && $shardFilePath !== $firstShardFilePath;

                $this->copyShard($mainHandle, $shardFilePath, $skipHeader);
            }
        } finally {
            $this->fileDriver->fileUnlock($mainHandle);
            $this->fileDriver->fileClose($mainHandle);
        }
    }

    /**
     * Copy one shard file into the open main file handle.
     *
     * @param resource $mainHandle
     * @param string $shardFilePath
     * @param bool $skipHeader
     * @return void
     * @throws FileSystemException
     */
    private function copyShard($mainHandle, string $shardFilePath, bool $skipHeader): void
    {
        if (!$this->fileDriver->isReadable($shardFilePath)) {
            throw new \RuntimeException(sprintf('Shard file is not readable: %s', $shardFilePath));
        }

        $hasContent = (int)($this->fileDriver->stat($shardFilePath)['size'] ?? 0) > 0;
        $shardHandle = $this->fileDriver->fileOpen($shardFilePath, 'rb');

        try {
            if ($skipHeader && $hasContent) {
                $this->fileDriver->fileReadLine($shardHandle, self::HEADER_MAX_LENGTH, "\n");
            }

            while (!$this->fileDriver->endOfFile($shardHandle)) {
                $chunk = $this->fileDriver->fileRead($shardHandle, self::READ_CHUNK_SIZE);
                if ($chunk !== '') {
                    $this->fileDriver->fileWrite($mainHandle, $chunk);
                }
            }
        } finally {
            $this->fileDriver->fileClose($shardHandle);
        }
    }
}
