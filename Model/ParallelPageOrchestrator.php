<?php
/**
 * Copyright (C) 2025 AthosCommerce <https://athoscommerce.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 */

declare(strict_types=1);

namespace AthosCommerce\FeedParallel\Model;

use AthosCommerce\FeedParallel\Model\Storage\ShardAppender;
use AthosCommerce\Feed\Logger\AthosCommerceLogger;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;

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
     * @param AthosCommerceLogger $logger
     * @param ShardAppender $shardAppender
     * @param JsonSerializer $jsonSerializer
     */
    public function __construct(
        AthosCommerceLogger $logger,
        ShardAppender $shardAppender,
        JsonSerializer $jsonSerializer
    ) {
        $this->logger = $logger;
        $this->shardAppender = $shardAppender;
        $this->jsonSerializer = $jsonSerializer;
    }

    /**
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
    ): array
    {
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

            $command = sprintf(
                '%s %s/bin/magento athoscommerce:feed-parallel:generate-page-range'
                . ' --task-id=%d --page-start=%d --page-end=%d --shard-file=%s --catalog-shard-file=%s --meta-file=%s',
                escapeshellarg($phpBinary),
                escapeshellarg($magentoRoot),
                $taskId,
                $range['start'],
                $range['end'],
                escapeshellarg($shardPath),
                escapeshellarg($catalogShardPath),
                escapeshellarg($metaPath)
            );

            $descriptorSpec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open($command, $descriptorSpec, $pipes, $magentoRoot);
            if (!is_resource($process)) {
                $this->cleanupWorkDirectory($workDir);
                throw new \RuntimeException(sprintf('Unable to start worker process for pages %d-%d', $range['start'], $range['end']));
            }

            $status = proc_get_status($process);
            $pid = is_array($status) ? (int)($status['pid'] ?? 0) : 0;

            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $processes[] = [
                'process' => $process,
                'stdout' => $pipes[1],
                'stderr' => $pipes[2],
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
            $output = '';
            $stderr = '';

            while (true) {
                $read = [$worker['stdout'], $worker['stderr']];
                $write = null;
                $except = null;

                if (stream_select($read, $write, $except, 1) === false) {
                    break;
                }

                foreach ($read as $stream) {
                    $chunk = stream_get_contents($stream);
                    if ($chunk === false || $chunk === '') {
                        continue;
                    }

                    if ($stream === $worker['stdout']) {
                        $output .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }

                $status = proc_get_status($worker['process']);
                if (!$status['running']) {
                    break;
                }
            }

            $exitCode = proc_close($worker['process']);

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
     * @param int $taskId
     * @return string
     */
    private function createWorkDirectory(int $taskId): string
    {
        $directory = BP . '/var/' . self::SHARD_DIR . '/' . $taskId;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create parallel work directory: %s', $directory));
        }

        return $directory;
    }

    /**
     * @param string $workDir
     * @return void
     */
    private function cleanupWorkDirectory(string $workDir): void
    {
        if (!is_dir($workDir)) {
            return;
        }

        /*$files = glob($workDir . '/*') ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($workDir);*/
    }

    /**
     * @param string[] $metaPaths
     * @return array{productCount: int, catalogRowCount: int}
     */
    private function readCountsFromMetaFiles(array $metaPaths): array
    {
        $productCount = 0;
        $catalogRowCount = 0;

        foreach ($metaPaths as $metaPath) {
            if (!is_readable($metaPath)) {
                throw new \RuntimeException(sprintf('Missing worker meta file: %s', $metaPath));
            }

            $payload = $this->jsonSerializer->unserialize((string)file_get_contents($metaPath));
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
