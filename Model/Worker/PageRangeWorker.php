<?php
/**
 * Copyright (C) 2025 AthosCommerce <https://athoscommerce.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 */

declare(strict_types=1);

namespace AthosCommerce\FeedParallel\Model\Worker;

use AthosCommerce\Feed\Api\TaskRepositoryInterface;
use AthosCommerce\Feed\Logger\AthosCommerceLogger;
use AthosCommerce\Feed\Model\Feed\ContextManagerInterface;
use AthosCommerce\Feed\Model\Feed\SpecificationBuilderInterface;
use AthosCommerce\Feed\Model\ItemsGenerator;
use AthosCommerce\FeedParallel\Model\PageRangeGenerator;
use AthosCommerce\FeedParallel\Model\PresignUrlFormatResolver;
use AthosCommerce\FeedParallel\Model\Storage\ShardWriter;

class PageRangeWorker
{
    /**
     * @var TaskRepositoryInterface
     */
    private $taskRepository;

    /**
     * @var SpecificationBuilderInterface
     */
    private $specificationBuilder;

    /**
     * @var ContextManagerInterface
     */
    private $contextManager;

    /**
     * @var ItemsGenerator
     */
    private $itemsGenerator;

    /**
     * @var PageRangeGenerator
     */
    private $pageRangeGenerator;

    /**
     * @var PresignUrlFormatResolver
     */
    private $presignUrlFormatResolver;

    /**
     * @var ShardWriter
     */
    private $shardWriter;

    /**
     * @var AthosCommerceLogger
     */
    private $logger;

    /**
     * @param TaskRepositoryInterface $taskRepository
     * @param AthosCommerceLogger $logger
     * @param SpecificationBuilderInterface $specificationBuilder
     * @param ContextManagerInterface $contextManager
     * @param ItemsGenerator $itemsGenerator
     * @param PageRangeGenerator $pageRangeGenerator
     * @param PresignUrlFormatResolver $presignUrlFormatResolver
     * @param ShardWriter $shardWriter
     */
    public function __construct(
        TaskRepositoryInterface $taskRepository,
        AthosCommerceLogger $logger,
        SpecificationBuilderInterface $specificationBuilder,
        ContextManagerInterface $contextManager,
        ItemsGenerator $itemsGenerator,
        PageRangeGenerator $pageRangeGenerator,
        PresignUrlFormatResolver $presignUrlFormatResolver,
        ShardWriter $shardWriter
    ) {
        $this->taskRepository = $taskRepository;
        $this->logger = $logger;
        $this->specificationBuilder = $specificationBuilder;
        $this->contextManager = $contextManager;
        $this->itemsGenerator = $itemsGenerator;
        $this->pageRangeGenerator = $pageRangeGenerator;
        $this->presignUrlFormatResolver = $presignUrlFormatResolver;
        $this->shardWriter = $shardWriter;
    }

    /**
     * @param int $taskId
     * @param int $pageStart
     * @param int $pageEnd
     * @param string $shardFile
     * @param string $catalogShardFile
     * @param string $metaFile
     * @return int
     */
    public function execute(
        int $taskId,
        int $pageStart,
        int $pageEnd,
        string $shardFile,
        string $catalogShardFile,
        string $metaFile
    ): int {
        $task = $this->taskRepository->get($taskId);
        $feedSpecification = $this->specificationBuilder->build($task->getPayload());
        if (!$this->presignUrlFormatResolver->apply($feedSpecification)) {
            throw new \RuntimeException('Unable to resolve feed format from pre-signed URL.');
        }

        $pid = getmypid() !== false ? (int)getmypid() : 0;
        $this->logger->notice('[FeedParallel] Worker execution started', [
            'taskId' => $taskId,
            'pid' => $pid,
            'pageStart' => $pageStart,
            'pageEnd' => $pageEnd,
            'shardFile' => $shardFile,
            'catalogShardFile' => $catalogShardFile,
            'metaFile' => $metaFile,
        ]);

        $this->itemsGenerator->resetDataProviders($feedSpecification);
        $this->contextManager->setContextFromSpecification($feedSpecification);

        try {
            $counts = $this->pageRangeGenerator->generate(
                $feedSpecification,
                $pageStart,
                $pageEnd,
                $shardFile,
                $catalogShardFile !== '' ? $catalogShardFile : null
            );
            $productCount = $counts['productCount'];
            $catalogRowCount = $counts['catalogRowCount'];

            $this->shardWriter->writeMeta($productCount, $metaFile, $catalogRowCount);

            $this->logger->notice('[FeedParallel] Worker execution completed', [
                'taskId' => $taskId,
                'pid' => $pid,
                'pageStart' => $pageStart,
                'pageEnd' => $pageEnd,
                'productCount' => $productCount,
                'shardFile' => $shardFile,
                'catalogShardFile' => $catalogShardFile,
                'catalogRowCount' => $catalogRowCount,
                'metaFile' => $metaFile,
            ]);

            return $productCount;
        } finally {
            $this->itemsGenerator->resetDataProviders($feedSpecification);
            $this->contextManager->resetContext();
        }
    }
}
