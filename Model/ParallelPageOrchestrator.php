<?php
/**
 * Copyright (C) 2025 AthosCommerce <https://athoscommerce.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 */

declare(strict_types=1);

namespace AthosCommerce\FeedParallel\Model;

use AthosCommerce\FeedParallel\Console\Command\FeedPageWorkerCommand;
use AthosCommerce\FeedParallel\Model\Storage\ShardAppender;
use AthosCommerce\Feed\Api\AppConfigInterface;
use AthosCommerce\Feed\Logger\AthosCommerceLogger;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

class ParallelPageOrchestrator
{
    public const SHARD_DIR = 'athoscommerce/parallel';

    /**
     * @var AthosCommerceLogger
     */
    private $logger;

    /**
     * @var ShardAppender
     */
    private $shardAppender;

    /**
     * @var JsonSerializer
     */
    private $jsonSerializer;

    /**
     * @var FileDriver
     */
    private $fileDriver;

    /**
     * @var AppConfigInterface
     */
    private $appConfig;

    /**
     * @param AthosCommerceLogger $logger
     * @param ShardAppender $shardAppender
     * @param JsonSerializer $jsonSerializer
     * @param FileDriver $fileDriver
     * @param AppConfigInterface $appConfig
     */
    public function __construct(
        AthosCommerceLogger $logger,
        ShardAppender $shardAppender,
        JsonSerializer $jsonSerializer,
        FileDriver $fileDriver,
        AppConfigInterface $appConfig
    ) {
        $this->logger = $logger;
        $this->shardAppender = $shardAppender;
        $this->jsonSerializer = $jsonSerializer;
        $this->fileDriver = $fileDriver;
        $this->appConfig = $appConfig;
    }

    /**
     * Run page-range workers in separate CLI processes and merge their shards into the main feed file.
     *
     * @param int $taskId
     * @param int $pageCount
     * @param int $workerCount
     * @param string $mainFilePath
     * @param string|null $catalogMainFilePath
     * @return array{productCount: int, catalogRowCount: int}
     */
    public function run(
        int $taskId,
        int $pageCount,
        int $workerCount,
        string $mainFilePath,
        ?string $catalogMainFilePath = null
    ): array {
        if ($pageCount <= 0) {
            return ['productCount' => 0, 'catalogRowCount' => 0];
        }

        $workerCount = min($workerCount, $pageCount);
        $ranges = $this->buildPageRanges($pageCount, $workerCount);
        $workDir = $this->createWorkDirectory($taskId);
        $phpBinary = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
        $magentoRoot = BP;

        $processes = [];
        $shardPaths = [];
        $catalogShardPaths = [];
        $metaPaths = [];

        foreach ($ranges as $index => $range) {
            $shardPath = $workDir . '/shard_' . $index . '.jsonl';
            $catalogShardPath = $catalogMainFilePath !== null ? $workDir . '/catalog_shard_' . $index . '.txt' : '';
            $metaPath = $workDir . '/shard_' . $index . '.meta.json';
            $shardPaths[] = $shardPath;
            if ($catalogShardPath !== '') {
                $catalogShardPaths[] = $catalogShardPath;
            }
            $metaPaths[] = $metaPath;

            $process = new Process(
                [
                    $phpBinary,
                    $magentoRoot . '/bin/magento',
                    FeedPageWorkerCommand::COMMAND_NAME,
                    '--task-id=' . $taskId,
                    '--page-start=' . $range['start'],
                    '--page-end=' . $range['end'],
                    '--shard-file=' . $shardPath,
                    '--catalog-shard-file=' . $catalogShardPath,
                    '--meta-file=' . $metaPath,
                ],
                $magentoRoot,
                null,
                null,
                null
            );

            try {
                $process->start();
            } catch (ProcessRuntimeException $exception) {
                $this->cleanupWorkDirectory($workDir);
                throw new \RuntimeException(
                    sprintf('Unable to start worker process for pages %d-%d', $range['start'], $range['end']),
                    0,
                    $exception
                );
            }

            $pid = (int)$process->getPid();

            $processes[] = [
                'process' => $process,
                'range' => $range,
                'index' => $index,
                'pid' => $pid,
            ];

            $this->logger->notice('[FeedParallel] Worker started', [
                'taskId' => $taskId,
                'worker' => $index + 1,
                'pid' => $pid,
                'pageStart' => $range['start'],
                'pageEnd' => $range['end'],
                'shardFile' => $shardPath,
                'catalogShardFile' => $catalogShardPath,
                'metaFile' => $metaPath,
            ]);
        }

        $failedWorkers = [];
        foreach ($processes as $worker) {
            /** @var Process $process */
            $process = $worker['process'];
            try {
                $exitCode = $process->wait();
            } catch (ProcessSignaledException $exception) {
                $exitCode = 128 + $exception->getSignal();
            }
            $output = $process->getOutput();
            $stderr = $process->getErrorOutput();

            if ($stderr !== '') {
                $this->logger->debug('[FeedParallel] Worker stderr', [
                    'taskId' => $taskId,
                    'worker' => $worker['index'] + 1,
                    'pid' => $worker['pid'],
                    'stderr' => trim($stderr),
                ]);
            }

            if ($output !== '') {
                $this->logger->debug('[FeedParallel] Worker stdout', [
                    'taskId' => $taskId,
                    'worker' => $worker['index'] + 1,
                    'pid' => $worker['pid'],
                    'stdout' => trim($output),
                ]);
            }

            if ($exitCode !== 0) {
                $failedWorkers[] = [
                    'worker' => $worker['index'] + 1,
                    'pid' => $worker['pid'],
                    'exitCode' => $exitCode,
                    'pageStart' => $worker['range']['start'],
                    'pageEnd' => $worker['range']['end'],
                ];
            }
        }

        if ($failedWorkers !== []) {
            $this->cleanupWorkDirectory($workDir);
            throw new \RuntimeException(
                sprintf(
                    'Parallel feed workers failed: %s',
                    json_encode($failedWorkers)
                )
            );
        }

        $this->logger->notice('[FeedParallel] All workers exited successfully; merging shards', [
            'taskId' => $taskId,
            'workerCount' => count($ranges),
            'mainFile' => $mainFilePath,
            'catalogMainFile' => $catalogMainFilePath,
        ]);

        $this->shardAppender->appendShards($mainFilePath, $shardPaths);
        if ($catalogMainFilePath !== null) {
            $this->shardAppender->appendShardsWithSingleHeader($catalogMainFilePath, $catalogShardPaths);
        }
        $counts = $this->readCountsFromMetaFiles($metaPaths);
        $this->cleanupWorkDirectory($workDir);

        $this->logger->notice('[FeedParallel] Workers completed and shards merged', [
            'taskId' => $taskId,
            'workerCount' => count($ranges),
            'productCount' => $counts['productCount'],
            'catalogRowCount' => $counts['catalogRowCount'],
            'mainFile' => $mainFilePath,
            'catalogMainFile' => $catalogMainFilePath,
        ]);

        return $counts;
    }

    /**
     * Split the page count into contiguous, inclusive page ranges (one per worker).
     *
     * @param int $pageCount
     * @param int $workerCount
     * @return array<int, array{start: int, end: int}>
     */
    private function buildPageRanges(int $pageCount, int $workerCount): array
    {
        $pagesPerWorker = (int)ceil($pageCount / $workerCount);
        $ranges = [];
        $start = 1;

        while ($start <= $pageCount) {
            $end = min($start + $pagesPerWorker - 1, $pageCount);
            $ranges[] = ['start' => $start, 'end' => $end];
            $start = $end + 1;
        }

        return $ranges;
    }

    /**
     * Create the per-task directory that holds worker shard and meta files.
     *
     * @param int $taskId
     * @return string
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    private function createWorkDirectory(int $taskId): string
    {
        $directory = BP . '/var/' . self::SHARD_DIR . '/' . $taskId;
        if (!$this->fileDriver->isDirectory($directory)) {
            $this->fileDriver->createDirectory($directory, 0775);
        }

        return $directory;
    }

    /**
     * Delete the per-task work directory with its shard and meta files.
     *
     * Files are kept for troubleshooting when debug mode is enabled (athoscommerce/feed/debug in app/etc/env.php).
     * A failed cleanup is logged and never fails the feed task.
     *
     * @param string $workDir
     * @return void
     */
    private function cleanupWorkDirectory(string $workDir): void
    {
        try {
            if (!$this->fileDriver->isDirectory($workDir)) {
                return;
            }

            if ($this->appConfig->isDebug()) {
                $this->logger->notice('[FeedParallel] Debug mode enabled; keeping shard files', [
                    'workDir' => $workDir,
                ]);
                return;
            }

            $this->fileDriver->deleteDirectory($workDir);
        } catch (\Exception $exception) {
            $this->logger->warning('[FeedParallel] Unable to delete shard files', [
                'workDir' => $workDir,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Sum the product and catalog row counts reported by each worker.
     *
     * @param string[] $metaPaths
     * @return array{productCount: int, catalogRowCount: int}
     */
    private function readCountsFromMetaFiles(array $metaPaths): array
    {
        $productCount = 0;
        $catalogRowCount = 0;

        foreach ($metaPaths as $metaPath) {
            if (!$this->fileDriver->isReadable($metaPath)) {
                throw new \RuntimeException(sprintf('Missing worker meta file: %s', $metaPath));
            }

            $payload = $this->jsonSerializer->unserialize((string)$this->fileDriver->fileGetContents($metaPath));
            if (!is_array($payload)) {
                throw new \RuntimeException(sprintf('Invalid worker meta file: %s', $metaPath));
            }

            $productCount += (int)($payload['productCount'] ?? 0);
            $catalogRowCount += (int)($payload['catalogRowCount'] ?? 0);
        }

        return [
            'productCount' => $productCount,
            'catalogRowCount' => $catalogRowCount,
        ];
    }
}
