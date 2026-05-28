# Vortos Object Store

First-class object storage for Vortos with Cloudflare R2 defaults and S3-compatible semantics.

## Design Rules

- Large user files should use direct-to-cloud uploads. The backend issues a presigned PUT URL or POST policy and stores object keys, not 200MB request bodies.
- Temporary uploads should use the configured `tmp/` prefix and a bucket lifecycle rule that deletes stale temporary objects.
- Promotion from temporary keys to permanent keys can be queued through the object-store outbox so registration persistence and file adoption are transactionally coordinated.
- LocalStack is intentionally not part of this package. Provider integration tests are gated by real R2/S3 credentials in CI.

## Configuration

```php
use Vortos\ObjectStore\Config\ObjectStoreObservabilitySection;
use Vortos\ObjectStore\DependencyInjection\VortosObjectStoreConfig;

return static function (VortosObjectStoreConfig $config): void {
    $config
        ->driver('s3')
        ->provider('r2')
        ->region('auto')
        ->bucket($_ENV['OBJECT_STORE_BUCKET']);

    $config->client()
        ->accountId($_ENV['OBJECT_STORE_ACCOUNT_ID'])
        ->credentials($_ENV['OBJECT_STORE_ACCESS_KEY_ID'], $_ENV['OBJECT_STORE_SECRET_ACCESS_KEY']);

    $config->bucketConfig()
        ->temporaryKeyPrefix('tmp')
        ->publicBaseUrl($_ENV['OBJECT_STORE_PUBLIC_BASE_URL'] ?? null)
        ->maxUploadSizeBytes(5_368_709_120)
        ->maxPresignTtlSeconds(3600);

    $config->lifecycle()
        ->enabled(true)
        ->requireConfirmation(true);

    $config->observability()
        ->disableLoggingFor(ObjectStoreObservabilitySection::Presign);
};
```

## Direct Upload Flow

1. The application calls `DirectUploadManagerInterface::createUploadIntent()` for a `tmp/...` key.
2. The frontend uploads directly to R2/S3 using the returned signed URL and required headers.
3. The application persists domain data with the temporary object key.
4. After validation, `promote()` copies the object to a permanent key and optionally deletes the temporary source.

## Trusted Server-Side Transfers

`ServerSideMultipartUploadManagerInterface` is for backend-owned transfers such
as imports, exports, migrations, and CLI jobs where the server already owns the
source stream or file. It validates S3 part limits, keeps memory bounded by part
size, retries transient part failures, aborts failed uploads, and can report
per-part progress.

Public/browser uploads should still use the direct-to-cloud flow above.

## Service Guarantees

The injected interface defines the delivery guarantee. There is no hidden config switch.

```php
// Business workflows: outbox row must be atomic with domain DB changes.
// Use only inside CommandBus command handlers.
DirectUploadManagerInterface $uploads;
ObjectStoreInterface $objects;

// Maintenance workflows: outbox reliability, but only the outbox row is transactional.
StandaloneDirectUploadManagerInterface $uploads;
StandaloneObjectStoreInterface $objects;

// Diagnostics/probes: direct provider call, no outbox.
ImmediateDirectUploadManagerInterface $uploads;
ImmediateObjectStoreInterface $objects;
```

Transactional interfaces fail fast outside an active transaction. For normal application code this is automatic because `CommandBus` owns the transaction boundary.

## Lifecycle Provisioning

Lifecycle rules are infrastructure mutations, so this package never applies them during HTTP requests, workers, or container boot.

Use the explicit command:

```bash
php bin/console vortos:object-store:lifecycle show
php bin/console vortos:object-store:lifecycle plan
php bin/console vortos:object-store:lifecycle apply --confirm
php bin/console vortos:object-store:lifecycle remove --confirm
```

The managed rule ID defaults to `vortos-object-store-expire-temporary-uploads`. The manager reads the existing bucket lifecycle config, upserts only that managed rule, preserves unrelated rules, and writes the merged lifecycle config back through the S3-compatible API.

R2 and S3 lifecycle expiration uses day-level semantics. `bucket.orphan_ttl_seconds` must be at least `86400` unless `roundUpMinimumLifecycleDay(true)` is explicitly enabled.

## Commands

```bash
php bin/console vortos:object-store:head <key>
php bin/console vortos:object-store:presign <key>
php bin/console vortos:object-store:presign <key> --upload --content-type=video/mp4 --max-size=209715200
php bin/console vortos:object-store:relay
php bin/console vortos:object-store:lifecycle <show|plan|apply|remove>
php bin/console vortos:object-store:multipart list --prefix=imports/
php bin/console vortos:object-store:multipart abort --key=imports/big.csv --upload-id=<id> --confirm
php bin/console vortos:object-store:multipart abort-stale --older-than="-24 hours" --dry-run
php bin/console vortos:worker:install --worker=object-store-outbox-relay
```

## Observability

Logging, tracing, and metrics are enabled by default and use the framework packages. Applications can opt out globally:

```php
$config->observability()->logging(false)->tracing(false)->metrics(false);
```

They can also opt out by section using enums:

```php
$config->observability()
    ->disableLoggingFor(ObjectStoreObservabilitySection::Presign)
    ->disableTracingFor(ObjectStoreObservabilitySection::DirectUpload)
    ->disableMetricsFor(ObjectStoreObservabilitySection::Outbox)
    ->disableLoggingFor(ObjectStoreObservabilitySection::Lifecycle);
```
