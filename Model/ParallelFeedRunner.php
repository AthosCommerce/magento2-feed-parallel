<?php
/**
 * Copyright (C) 2025 AthosCommerce <https://athoscommerce.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 */

declare(strict_types=1);

namespace AthosCommerce\FeedParallel\Model;

use AthosCommerce\Feed\Api\AppConfigInterface;
use AthosCommerce\Feed\Api\Data\FeedSpecificationInterface;
use AthosCommerce\Feed\Api\MetadataInterface;
use AthosCommerce\Feed\Api\TaskRepositoryInterface;
use AthosCommerce\Feed\Logger\AthosCommerceLogger;
use AthosCommerce\Feed\Model\CollectionProcessor;
use AthosCommerce\Feed\Model\Feed\CollectionConfigInterface;
use AthosCommerce\Feed\Model\Feed\ContextManagerInterface;
use AthosCommerce\Feed\Model\Feed\Storage\CatalogSignedUrlStorage;
use AthosCommerce\Feed\Model\Feed\Storage\PreSignedUrlStorage;
use AthosCommerce\Feed\Model\Feed\StorageInterface;
use AthosCommerce\Feed\Model\ItemsGenerator;
use AthosCommerce\Feed\Model\Metric\CollectorInterface;
use Exception;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\RuntimeException;

class ParallelFeedRunner
{
    /**
     * @var CollectionProcessor
     */
    private $collectionProcessor;

    /**
     * @var ItemsGenerator
     */
    private $itemsGenerator;

    /**
     * @var CollectionConfigInterface
     */
    private $collectionConfig;

    /**
     * @var StorageInterface
     */
    private $storage;

    /**
     * @var ContextManagerInterface
     */
    private $contextManager;

    /**
     * @var CollectorInterface
     */
    private $metricCollector;

    /**
     * @var AppConfigInterface
     */
    private $appConfig;

    /**
     * @var TaskRepositoryInterface
     */
    private $taskRepository;

    /**
     * @var AthosCommerceLogger
     */
    private $logger;

    /**
     * @var PresignUrlFormatResolver
     */
    private $presignUrlFormatResolver;

    /**
     * @var ParallelPageOrchestrator
     */
    private $parallelPageOrchestrator;

    /**
     * @var PreSignedUrlStorage
     */
    private $preSignedUrlStorage;

    /**
     * @var CatalogSignedUrlStorage
     */
    private $catalogSignedUrlStorage;

    private $gcStatus = false;

    /**
     * @param CollectionProcessor $collectionProcessor
     * @param ItemsGenerator $itemsGenerator
     * @param CollectionConfigInterface $collectionConfig
     * @param StorageInterface $storage
     * @param PreSignedUrlStorage $preSignedUrlStorage
     * @param CatalogSignedUrlStorage $catalogSignedUrlStorage
     * @param ContextManagerInterface $contextManager
     * @param CollectorInterface $metricCollector
     * @param AppConfigInterface $appConfig
     * @param TaskRepositoryInterface $taskRepository
     * @param AthosCommerceLogger $logger
     * @param PresignUrlFormatResolver $presignUrlFormatResolver
     * @param ParallelPageOrchestrator $parallelPageOrchestrator
     */
    public function __construct(
        CollectionProcessor $collectionProcessor,
        ItemsGenerator $itemsGenerator,
        CollectionConfigInterface $collectionConfig,
        StorageInterface $storage,
        PreSignedUrlStorage $preSignedUrlStorage,
        CatalogSignedUrlStorage $catalogSignedUrlStorage,
        ContextManagerInterface $contextManager,
        CollectorInterface $metricCollector,
        AppConfigInterface $appConfig,
        TaskRepositoryInterface $taskRepository,
        AthosCommerceLogger $logger,
        PresignUrlFormatResolver $presignUrlFormatResolver,
        ParallelPageOrchestrator $parallelPageOrchestrator
    ) {
        $this->collectionProcessor = $collectionProcessor;
        $this->itemsGenerator = $itemsGenerator;
        $this->collectionConfig = $collectionConfig;
        $this->storage = $storage;
        $this->preSignedUrlStorage = $preSignedUrlStorage;
        $this->catalogSignedUrlStorage = $catalogSignedUrlStorage;
        $this->contextManager = $contextManager;
        $this->metricCollector = $metricCollector;
        $this->appConfig = $appConfig;
        $this->taskRepository = $taskRepository;
        $this->logger = $logger;
        $this->presignUrlFormatResolver = $presignUrlFormatResolver;
        $this->parallelPageOrchestrator = $parallelPageOrchestrator;
    }

    /**
     * @param FeedSpecificationInterface $feedSpecification
     * @param int $workerCount
     * @return bool
     */
    public function canRunInParallel(FeedSpecificationInterface $feedSpecification, int $workerCount): bool
    {
        if ($workerCount <= 1) {
            return false;
        }

        return strtolower((string)$feedSpecification->getFormat()) === MetadataInterface::FORMAT_JSON;
    }

    /**
     * @param FeedSpecificationInterface $feedSpecification
     * @param int $id
     * @param int $workerCount
     * @return void
     * @throws CouldNotSaveException
     * @throws FileSystemException
     * @throws NoSuchEntityException
     * @throws RuntimeException
     */
    public function execute(FeedSpecificationInterface $feedSpecification, int $id, int $workerCount): void
    {
        $format = $feedSpecification->getFormat();
        $this->logger->notice('[FeedParallel] Parallel runner entered', [
            'taskId' => $id,
            'format' => $format,
            'workerCount' => $workerCount,
            'catalogPreSignedUrl' => $feedSpecification->getCatalogPreSignedUrl(),
        ]);
        $this->logger->info('[FeedParallel] Product feed generation started', [
            'method' => __METHOD__,
            'entityId' => $id,
            'format' => $format,
            'workerCount' => $workerCount,
        ]);

        $startTime = microtime(true);

        if (!$this->presignUrlFormatResolver->apply($feedSpecification)) {
            throw new Exception((string)__('Not able to set the format(%1) from PreSignedUrl', $format));
        }

        if (!$this->storage->isSupportedFormat((string)$feedSpecification->getFormat())) {
            throw new Exception((string)__('%1 is not supported format', $feedSpecification->getFormat()));
        }

        if (!$this->canRunInParallel($feedSpecification, $workerCount)) {
            throw new Exception('[FeedParallel] Parallel execution is not supported for this feed specification.');
        }

        $this->initialize($feedSpecification);

        try {
            $collection = $this->collectionProcessor->getCollection($feedSpecification);
            $pageSize = $this->collectionConfig->getPageSize();
            $collection->setPageSize($pageSize);
            $pageCount = $this->getPageCount($collection);

            $this->logger->info('[FeedParallel] Product collection details', [
                'method' => __METHOD__,
                'entityId' => $id,
                'pageSize' => $pageSize,
                'pageCount' => $pageCount,
                'workerCount' => $workerCount,
            ]);

            $mainFilePath = $this->preSignedUrlStorage->getFile()->getAbsolutePath();
            if (!$mainFilePath) {
                throw new Exception('[FeedParallel] Main feed file path is not available.');
            }
            $catalogMainFilePath = null;
            if (!empty($feedSpecification->getCatalogPreSignedUrl())) {
                $catalogMainFilePath = $this->catalogSignedUrlStorage->getFile()->getAbsolutePath();
                if (!$catalogMainFilePath) {
                    throw new Exception('[FeedParallel] Catalog feed file path is not available.');
                }
            }

             $this->logger->notice('[FeedParallel] Main feed file prepared for shard merge', [
                 'taskId' => $id,
                 'mainFilePath' => $mainFilePath,
                 'catalogMainFilePath' => $catalogMainFilePath,
                 'pageCount' => $pageCount,
                 'pageSize' => $pageSize,
                 'workerCount' => $workerCount,
             ]);

            $this->preSignedUrlStorage->getFile()->commit();
            if ($catalogMainFilePath !== null) {
                $this->catalogSignedUrlStorage->getFile()->commit();
            }

            $counts = $this->parallelPageOrchestrator->run(
                $id,
                $pageCount,
                $workerCount,
                $mainFilePath,
                $catalogMainFilePath
            );
            $productCount = $counts['productCount'];
            $catalogRowCount = $counts['catalogRowCount'];

            $task = $this->taskRepository->get($id);
            $task->setProductCount($productCount);
            $task->setPcProductCount($catalogRowCount);
            $this->taskRepository->save($task);

            $this->logger->notice('[FeedParallel] Parallel worker phase completed', [
                'taskId' => $id,
                'productCount' => $productCount,
                'catalogRowCount' => $catalogRowCount,
                'workerCount' => $workerCount,
            ]);

            $this->finalize($feedSpecification, $id);

            $this->logger->debug('[FeedParallel] Product feed generation completed', [
                'entityId' => $id,
                'totalSeconds' => microtime(true) - $startTime,
                'productCount' => $productCount,
            ]);
        } catch (Exception $exception) {
            $this->storage->rollback();
            if (!empty($feedSpecification->getCatalogPreSignedUrl())) {
                $this->catalogSignedUrlStorage->rollback();
            }
            $this->logger->error('[FeedParallel] Error during parallel feed generation', [
                'method' => __METHOD__,
                'entityId' => $id,
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);
            throw $exception;
        }
    }

    /**
     * @param FeedSpecificationInterface $feedSpecification
     * @return void
     */
    private function initialize(FeedSpecificationInterface $feedSpecification): void
    {
        $this->gcStatus = gc_enabled();
        if (!$this->gcStatus) {
            gc_enable();
        }

        $this->itemsGenerator->resetDataProviders($feedSpecification);
        $this->contextManager->setContextFromSpecification($feedSpecification);
        $this->storage->initiate($feedSpecification);
        if (!empty($feedSpecification->getCatalogPreSignedUrl())) {
            $this->catalogSignedUrlStorage->initiate($feedSpecification);
        }
    }

    /**
     * @param FeedSpecificationInterface $feedSpecification
     * @param int $id
     * @return void
     * @throws Exception
     */
    private function finalize(FeedSpecificationInterface $feedSpecification, int $id): void
    {
        $this->itemsGenerator->resetDataProviders($feedSpecification);
        $this->logger->notice('[FeedParallel] Finalizing parallel feed storage', [
            'taskId' => $id,
            'format' => $feedSpecification->getFormat(),
        ]);
        $this->logger->info('[FeedParallel] File storage in s3 started', [
            'method' => __METHOD__,
            'entityId' => $id,
            'format' => $feedSpecification->getFormat(),
        ]);

        try {
            if (!empty($feedSpecification->getCatalogPreSignedUrl())) {
                $this->logger->notice('[FeedParallel] Finalizing parallel catalog storage', [
                    'taskId' => $id,
                    'catalogPreSignedUrl' => $feedSpecification->getCatalogPreSignedUrl(),
                ]);
                $this->catalogSignedUrlStorage->commit($id);
            }
            $this->storage->commit($id);
        } finally {
            $this->metricCollector->print(
                CollectorInterface::CODE_PRODUCT_FEED,
                CollectorInterface::PRINT_TYPE_FULL
            );
        }

        $this->logger->info('[FeedParallel] File storage in s3 completed', [
            'method' => __METHOD__,
            'entityId' => $id,
            'format' => $feedSpecification->getFormat(),
        ]);

        $this->metricCollector->reset(CollectorInterface::CODE_PRODUCT_FEED);
        $this->contextManager->resetContext();

        if (!$this->gcStatus) {
            gc_disable();
        }
    }

    /**
     * @param Collection $collection
     * @return int
     * @throws FileSystemException
     * @throws RuntimeException
     */
    private function getPageCount(Collection $collection): int
    {
        $pageCount = null;
        if ($this->appConfig->isDebug()) {
            $pageCount = $this->appConfig->getValue('product_page_count');
        }

        if ($pageCount === null) {
            $pageCount = $collection->getLastPageNumber();
        }

        return (int)$pageCount;
    }
}
