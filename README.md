# AthosCommerce FeedParallel for Magento 2

Optional add-on module for **parallel bulk feed generation** using separate Magento CLI worker processes.

This module does **not** modify `AthosCommerce_Feed`. Install it only on merchants that need faster full-sync feed generation.

## Requirements

- Magento Open Source / Adobe Commerce 2.4.x
- PHP 8.1 – 8.5
- [`athoscommerce/magento2-module`](https://packagist.org/packages/athoscommerce/magento2-module) (`AthosCommerce_Feed`, the core Athos connector)
- PHP `proc_open` enabled (worker processes are started with `symfony/process`)
- JSON feed format (parallel mode)

## Installation

### Composer (recommended)

```bash
composer require athoscommerce/magento2-feed-parallel
bin/magento module:enable AthosCommerce_FeedParallel
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

`setup:di:compile` is only needed in production mode.

### Manual

Copy the module to `app/code/AthosCommerce/FeedParallel`, then run the same `bin/magento` commands as above.

## Configuration

### 1. Enable the add-on module (`app/etc/env.php`)

```php
'athoscommerce_feed_parallel' => [
    'enabled' => false
],
'athoscommerce' => [
    'feed' => [
        'product' => [
            'page' => [
                'size' => 100
            ]
        ]
    ]
],
```

| Key | Description |
|-----|-------------|
| `athoscommerce_feed_parallel/enabled` | Master switch for the add-on on this Magento instance (`true` / `false`) |
| `athoscommerce/feed/product/page/size` | Number of products per collection page. Pages are split into ranges across workers. Default: `2000` |

### 2. Set worker count in the Athos task payload

> **Note:** You don't set this value in Magento. The AthosCommerce backend sends it in the task payload for each feed task.

```json
{
  "store": "default",
  "preSignedUrl": "https://...",
  "parallelPageWorkers": 4
}
```

| Payload key | Description |
|-------------|-------------|
| `parallelPageWorkers` | Number of page-range workers for this task (recommended) |
| `parallel_page_workers` | Snake-case alias, also supported |

| Value | Behavior |
|-------|----------|
| Missing, `0`, or `1` | Sequential feed generation (core Athos path) |
| `2` or higher | Parallel page workers for this task |

Example: Athos sends `"parallelPageWorkers": 4` on a large catalog full-sync task; a smaller store task omits the field and stays sequential.

## Usage

No cron changes are required. Existing feed execution continues to work:

```bash
bin/magento athoscommerce:feed:execute-pending-tasks --store=default
```

## Behavior

- **One output file** — worker shard files are merged into the main JSONL feed file before the normal S3 upload
- **Catalog export supported** — when the task has a catalog pre-signed URL, workers also write catalog shards, which are merged into a single catalog file with one header row
- **Sequential fallback** when the add-on is disabled, payload worker count is `0`/`1`, or format is not JSON
- **Shard cleanup** — shard files in `var/athoscommerce/parallel/<taskId>/` are deleted after the merge, or when a worker fails. They are kept when `athoscommerce/feed/debug` is `true` in `app/etc/env.php`
- **Live indexing is unchanged**

## Uninstall / disable

Set `enabled` to `false`, or disable the module:

```bash
bin/magento module:disable AthosCommerce_FeedParallel
bin/magento setup:upgrade
```

To remove a Composer installation completely:

```bash
bin/magento module:disable AthosCommerce_FeedParallel
composer remove athoscommerce/magento2-feed-parallel
bin/magento setup:upgrade
```

Feed generation immediately returns to the core sequential implementation.

## Support

support@athoscommerce.com

## License

[GPL-3.0-only](https://www.gnu.org/licenses/gpl-3.0.html)
