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
use AthosCommerce\Feed\Api\GenerateFeedInterface;
use AthosCommerce\Feed\Logger\AthosCommerceLogger;
use AthosCommerce\Feed\Model\GenerateFeed;

class ParallelGenerateFeed implements GenerateFeedInterface
{
    /**
     * @var GenerateFeed
     */
    private $sequentialGenerator;

    /**
     * @var ParallelFeedRunner
     */
    private $parallelFeedRunner;

    /**
     * @var PresignUrlFormatResolver
     */
    private $presignUrlFormatResolver;

    /**
     * @var TaskPayloadWorkerResolver
     */
    private $taskPayloadWorkerResolver;

    /**
     * @var AthosCommerceLogger
     */
    private $logger;

    /**
     * @param GenerateFeed $sequentialGenerator
     * @param ParallelFeedRunner $parallelFeedRunner
     * @param PresignUrlFormatResolver $presignUrlFormatResolver
     * @param TaskPayloadWorkerResolver $taskPayloadWorkerResolver
     * @param AthosCommerceLogger $logger
     */
    public function __construct(
        GenerateFeed $sequentialGenerator,
        ParallelFeedRunner $parallelFeedRunner,
        PresignUrlFormatResolver $presignUrlFormatResolver,
        TaskPayloadWorkerResolver $taskPayloadWorkerResolver,
        AthosCommerceLogger $logger
    ) {
        $this->sequentialGenerator = $sequentialGenerator;
        $this->parallelFeedRunner = $parallelFeedRunner;
        $this->presignUrlFormatResolver = $presignUrlFormatResolver;
        $this->taskPayloadWorkerResolver = $taskPayloadWorkerResolver;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function execute(FeedSpecificationInterface $feedSpecification, int $id): void
    {
        $this->presignUrlFormatResolver->apply($feedSpecification);

        $workerCount = $this->taskPayloadWorkerResolver->resolveFromTaskId($id);
        $this->logger->notice('[FeedParallel] Task resolved for feed generation', [
            'taskId' => $id,
            'workerCount' => $workerCount,
            'format' => $feedSpecification->getFormat(),
            'hasCatalogPreSignedUrl' => !empty($feedSpecification->getCatalogPreSignedUrl()),
        ]);

        if ($this->parallelFeedRunner->canRunInParallel($feedSpecification, $workerCount)) {
            $this->logger->notice('[FeedParallel] Parallel execution selected', [
                'taskId' => $id,
                'workerCount' => $workerCount,
                'format' => $feedSpecification->getFormat(),
            ]);
            $this->parallelFeedRunner->execute($feedSpecification, $id, $workerCount);
            return;
        }

        $this->logger->notice('[FeedParallel] Sequential fallback selected', [
            'taskId' => $id,
            'workerCount' => $workerCount,
            'format' => $feedSpecification->getFormat(),
            'hasCatalogPreSignedUrl' => !empty($feedSpecification->getCatalogPreSignedUrl()),
        ]);
        $this->sequentialGenerator->execute($feedSpecification, $id);
    }
}
