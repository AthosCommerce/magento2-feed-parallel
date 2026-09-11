<?php
/**
 * Copyright (C) 2025 AthosCommerce <https://athoscommerce.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 */

declare(strict_types=1);

namespace AthosCommerce\FeedParallel\Console\Command;

use AthosCommerce\FeedParallel\Model\Worker\PageRangeWorkerFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FeedPageWorkerCommand extends Command
{
    public const COMMAND_NAME = 'athoscommerce:feed-parallel:generate-page-range';

    private const OPTION_TASK_ID = 'task-id';
    private const OPTION_PAGE_START = 'page-start';
    private const OPTION_PAGE_END = 'page-end';
    private const OPTION_SHARD_FILE = 'shard-file';
    private const OPTION_CATALOG_SHARD_FILE = 'catalog-shard-file';
    private const OPTION_META_FILE = 'meta-file';

    /**
     * @var State
     */
    private $state;

    /**
     * @var PageRangeWorkerFactory
     */
    private $pageRangeWorkerFactory;

    /**
     * @param State $state
     * @param PageRangeWorkerFactory $pageRangeWorkerFactory
     * @param string|null $name
     */
    public function __construct(
        State $state,
        PageRangeWorkerFactory $pageRangeWorkerFactory,
        ?string $name = null
    ) {
        parent::__construct($name);
        $this->state = $state;
        $this->pageRangeWorkerFactory = $pageRangeWorkerFactory;
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('AthosCommerce FeedParallel: generate a page range into a shard file.')
            ->addOption(self::OPTION_TASK_ID, null, InputOption::VALUE_REQUIRED, 'Feed task entity ID')
            ->addOption(self::OPTION_PAGE_START, null, InputOption::VALUE_REQUIRED, 'First page number')
            ->addOption(self::OPTION_PAGE_END, null, InputOption::VALUE_REQUIRED, 'Last page number')
            ->addOption(self::OPTION_SHARD_FILE, null, InputOption::VALUE_REQUIRED, 'Shard JSONL output file')
            ->addOption(
                self::OPTION_CATALOG_SHARD_FILE,
                null,
                InputOption::VALUE_OPTIONAL,
                'Catalog shard TSV output file'
            )
            ->addOption(self::OPTION_META_FILE, null, InputOption::VALUE_REQUIRED, 'Shard meta JSON output file');

        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $taskId = (int)$input->getOption(self::OPTION_TASK_ID);
            $pageStart = (int)$input->getOption(self::OPTION_PAGE_START);
            $pageEnd = (int)$input->getOption(self::OPTION_PAGE_END);
            $shardFile = (string)$input->getOption(self::OPTION_SHARD_FILE);
            $catalogShardFile = (string)$input->getOption(self::OPTION_CATALOG_SHARD_FILE);
            $metaFile = (string)$input->getOption(self::OPTION_META_FILE);

            if ($taskId <= 0 || $pageStart <= 0 || $pageEnd <= 0 || $shardFile === '' || $metaFile === '') {
                throw new \InvalidArgumentException('Missing or invalid worker arguments.');
            }

            // Emulate the frontend area safely inside a closure
            $productCount = $this->state->emulateAreaCode(
                Area::AREA_FRONTEND,
                function () use ($taskId, $pageStart, $pageEnd, $shardFile, $catalogShardFile, $metaFile) {
                    $pageRangeWorker = $this->pageRangeWorkerFactory->create();
                    return $pageRangeWorker->execute(
                        $taskId,
                        $pageStart,
                        $pageEnd,
                        $shardFile,
                        $catalogShardFile,
                        $metaFile
                    );
                }
            );

            $output->writeln(sprintf(
                'Generated pages %d-%d (%d products) into %s',
                $pageStart,
                $pageEnd,
                $productCount,
                $shardFile
            ));

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }
}
