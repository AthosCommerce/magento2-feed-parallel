# AthosCommerce FeedParallel

Optional add-on module for **parallel bulk feed generation** using separate Magento CLI worker processes.

This module does **not** modify `AthosCommerce_Feed`. Install it only on merchants that need faster full-sync feed generation.

## Requirements

- `AthosCommerce_Feed` (core Athos connector)
- PHP `proc_open` enabled
- JSON feed format (parallel mode)
- No persistent catalog pre-signed URL on the feed task (falls back to sequential when catalog export is enabled)

## Installation

Copy or install this package next to the core module, then enable it:

```bash
bin/magento module:enable AthosCommerce_FeedParallel
bin/magento setup:upgrade
bin/magento cache:flush
```

Composer path example:

```json
{
    "require": {
        "athoscommerce/magento2-feed-parallel": "*"
    }
}
```

## Configuration

### 1. Enable the add-on module (`env.php`)

```php
'athoscommerce_feed_parallel' => [
    'enabled' => false
],
'athoscommerce' => [
    'feed' => [
        'debug' => true,
        'product' => [
            'page' => [
                'size' => 100
            ],
            'api' => [
                'mock' => true
            ],
            'delete' => [
                'file' => false
            ]
        ]
    ]
],
```

| Key | Description |
|-----|-------------|
| `enabled` | Master switch for the add-on on this Magento instance |

### 2. Set worker count in the Athos task payload

Worker count is sent **per feed task** from the Athos backend in the task payload:

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

When parallel mode applies, workers are spawned internally as:

```bash
bin/magento athoscommerce:feed-parallel:generate-page-range \
  --task-id=123 \
  --page-start=1 \
  --page-end=50 \
  --shard-file=/path/to/shard.jsonl \
  --meta-file=/path/to/shard.meta.json
```

Merchants normally do not run this command manually.

## Behavior

- **One output file** — worker shard files are merged into the main JSONL feed file before the normal S3 upload
- **Sequential fallback** when the add-on is disabled, payload worker count is `0`/`1`, format is not JSON, or catalog pre-signed URL is present
- **Live indexing is unchanged**

## Uninstall / disable

Set `enabled` to `false`, or disable the module:

```bash
bin/magento module:disable AthosCommerce_FeedParallel
bin/magento setup:upgrade
```

Feed generation immediately returns to the core sequential implementation.
