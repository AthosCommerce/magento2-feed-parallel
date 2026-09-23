<?php
/**
 * Copyright (C) 2025 AthosCommerce <https://athoscommerce.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 */

declare(strict_types=1);

namespace AthosCommerce\FeedParallel\Model;

use AthosCommerce\Feed\Api\TaskRepositoryInterface;
use AthosCommerce\Feed\Logger\AthosCommerceLogger;
use AthosCommerce\FeedParallel\Api\ConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class TaskPayloadWorkerResolver
{
    public const PAYLOAD_KEY_CAMEL = 'parallelPageWorkers';
    public const PAYLOAD_KEY_SNAKE = 'parallel_page_workers';

    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @var TaskRepositoryInterface
     */
    private $taskRepository;

    /**
     * @var AthosCommerceLogger
     */
    private $logger;

    /**
     * @param ConfigInterface $config
     * @param TaskRepositoryInterface $taskRepository
     * @param AthosCommerceLogger $logger
     */
    public function __construct(
        ConfigInterface $config,
        TaskRepositoryInterface $taskRepository,
        AthosCommerceLogger $logger
    ) {
        $this->config = $config;
        $this->taskRepository = $taskRepository;
        $this->logger = $logger;
    }

    /**
     * Resolve the worker count from the payload of the given feed task.
     *
     * @param int $taskId
     * @return int
     * @throws NoSuchEntityException
     */
    public function resolveFromTaskId(int $taskId): int
    {
        if (!$this->config->isEnabled()) {
            $this->logger->notice('[FeedParallel] Worker resolution skipped because module is disabled', [
                'taskId' => $taskId,
                'config' => $this->config->getSettingsAsArray(),
            ]);
            return 0;
        }

        $task = $this->taskRepository->get($taskId);
        $workerCount = $this->resolveFromPayload($task->getPayload());
        $payload = $task->getPayload();

        $this->logger->notice('[FeedParallel] Worker count resolved from task payload', [
            'taskId' => $taskId,
            'workerCount' => $workerCount,
            'payloadHasCamelKey' => array_key_exists(self::PAYLOAD_KEY_CAMEL, $payload),
            'payloadHasSnakeKey' => array_key_exists(self::PAYLOAD_KEY_SNAKE, $payload),
        ]);

        return $workerCount;
    }

    /**
     * Resolve the worker count sent by the AthosCommerce backend in the task payload.
     *
     * @param array $payload
     * @return int
     */
    public function resolveFromPayload(array $payload): int
    {
        if (!$this->config->isEnabled()) {
            return 0;
        }

        foreach ([self::PAYLOAD_KEY_CAMEL, self::PAYLOAD_KEY_SNAKE] as $key) {
            if (array_key_exists($key, $payload)) {
                return max(0, (int)$payload[$key]);
            }
        }

        return 0;
    }
}
