<?php
/**
 * Copyright (C) 2025 AthosCommerce <https://athoscommerce.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 */

declare(strict_types=1);

namespace AthosCommerce\FeedParallel\Model;

use AthosCommerce\Feed\Api\Data\FeedSpecificationInterface;
use AthosCommerce\Feed\Model\CollectionProcessor;
use AthosCommerce\Feed\Model\Feed\CollectionConfigInterface;
use AthosCommerce\Feed\Model\ItemsGenerator;
use AthosCommerce\FeedParallel\Model\Storage\CatalogShardWriter;
use AthosCommerce\FeedParallel\Model\Storage\ShardWriter;

class PageRangeGenerator
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
     * @var ShardWriter
     */
    private $shardWriter;

    /**
     * @var CatalogShardWriter
     */
    private $catalogShardWriter;

    /**
     * @param CollectionProcessor $collectionProcessor
     * @param ItemsGenerator $itemsGenerator
     * @param CollectionConfigInterface $collectionConfig
     * @param ShardWriter $shardWriter
     * @param CatalogShardWriter $catalogShardWriter
     */
    public function __construct(
        CollectionProcessor $collectionProcessor,
        ItemsGenerator $itemsGenerator,
        CollectionConfigInterface $collectionConfig,
        ShardWriter $shardWriter,
        CatalogShardWriter $catalogShardWriter
    ) {
        $this->collectionProcessor = $collectionProcessor;
        $this->itemsGenerator = $itemsGenerator;
        $this->collectionConfig = $collectionConfig;
        $this->shardWriter = $shardWriter;
        $this->catalogShardWriter = $catalogShardWriter;
    }

    /**
     * Generate feed rows for an inclusive page range and write them to a shard file.
     *
     * @param FeedSpecificationInterface $feedSpecification
     * @param int $pageStart
     * @param int $pageEnd
     * @param string $shardFilePath
     * @param string|null $catalogShardFilePath
     * @return array{productCount: int, catalogRowCount: int}
     */
    public function generate(
        FeedSpecificationInterface $feedSpecification,
        int $pageStart,
        int $pageEnd,
        string $shardFilePath,
        ?string $catalogShardFilePath = null
    ): array {
        if ($pageStart > $pageEnd) {
            return ['productCount' => 0, 'catalogRowCount' => 0];
        }

        if (is_file($shardFilePath)) {
            unlink($shardFilePath);
        }
        if ($catalogShardFilePath && is_file($catalogShardFilePath)) {
            unlink($catalogShardFilePath);
        }

        $collection = $this->collectionProcessor->getCollection($feedSpecification);
        $pageSize = $this->collectionConfig->getPageSize();
        $collection->setPageSize($pageSize);

        $productCount = 0;
        $catalogRowCount = 0;

        for ($currentPage = $pageStart; $currentPage <= $pageEnd; $currentPage++) {
            $collection->setCurPage($currentPage);
            $collection->load();
            $this->collectionProcessor->processAfterLoad($collection, $feedSpecification);

            $itemsData = $this->itemsGenerator->generate($collection->getItems(), $feedSpecification);
            $this->shardWriter->appendItems($itemsData, $shardFilePath);
            $productCount += count($itemsData);
            if ($catalogShardFilePath) {
                $catalogRowCount += $this->catalogShardWriter->appendItems($itemsData, $catalogShardFilePath);
            }

            $this->itemsGenerator->resetDataProvidersAfterFetchItems($feedSpecification);
            $collection->clear();
            $this->collectionProcessor->processAfterFetchItems($collection, $feedSpecification);
            gc_collect_cycles();
        }

        return [
            'productCount' => $productCount,
            'catalogRowCount' => $catalogRowCount,
        ];
    }
}
