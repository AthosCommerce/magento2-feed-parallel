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
use AthosCommerce\Feed\Api\MetadataInterface;

class PresignUrlFormatResolver
{
    /**
     * @param FeedSpecificationInterface $feedSpecification
     * @return bool
     */
    public function apply(FeedSpecificationInterface $feedSpecification): bool
    {
        $urlPath = parse_url($feedSpecification->getPreSignedUrl(), PHP_URL_PATH);
        if (!$urlPath) {
            return false;
        }

        $fileBaseExtension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
        $secondExtension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));

        if ($fileBaseExtension === MetadataInterface::FORMAT_JSON) {
            $feedSpecification->setFormat($fileBaseExtension);
        } elseif ($secondExtension === MetadataInterface::FORMAT_GZ) {
            if (strpos($urlPath, MetadataInterface::FORMAT_JSON_GZ) !== false) {
                $feedSpecification->setFormat(MetadataInterface::FORMAT_JSON);
            }
        } else {
            $feedSpecification->setFormat($fileBaseExtension);
        }

        return true;
    }
}
