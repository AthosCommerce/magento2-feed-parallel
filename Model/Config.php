<?php
/**
 * Copyright (C) 2025 AthosCommerce <https://athoscommerce.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 */

declare(strict_types=1);

namespace AthosCommerce\FeedParallel\Model;

use AthosCommerce\FeedParallel\Api\ConfigInterface;
use Magento\Framework\App\DeploymentConfig;

class Config implements ConfigInterface
{
    private const CONFIG_PATH = 'athoscommerce_feed_parallel';

    /**
     * @var DeploymentConfig
     */
    private $deploymentConfig;

    /**
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(DeploymentConfig $deploymentConfig)
    {
        $this->deploymentConfig = $deploymentConfig;
    }

    /**
     * @inheritdoc
     */
    public function isEnabled(): bool
    {
        $settings = $this->getSettings();

        return !empty($settings['enabled']);
    }

    /**
     * Read the module settings from deployment configuration.
     *
     * @return array<string, mixed>
     */
    private function getSettings(): array
    {
        $settings = $this->deploymentConfig->get(self::CONFIG_PATH);

        return is_array($settings) ? $settings : [];
    }

    /**
     * @inheritdoc
     */
    public function getSettingsAsArray(): array
    {
        return $this->getSettings();
    }
}
