# Vortos Object Store

S3-compatible object storage with Cloudflare R2 defaults, transactional outbox, direct-to-cloud uploads, and server-side multipart transfers.

## Design

- User file uploads go directly to R2/S3 via presigned URLs. The backend issues a signed URL, stores the object key, and promotes from `tmp/` to a permanent key after validation.
- Mutations (put, delete, copy, move) are queued through the outbox so file adoption is atomic with domain DB changes.
- `tmp/` keys are cleaned up by a bucket lifecycle rule managed via `vortos:object-store:lifecycle`.
- Integration tests run against real R2/S3 credentials. LocalStack is not part of this package.

## Configuration

```php
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
};
```

## Service Guarantees

The injected interface defines the delivery guarantee. There is no hidden config switch.

```php
// Business workflows: outbox row is atomic with domain DB changes.
// Use only inside CommandBus command handlers.
DirectUploadManagerInterface $uploads;
ObjectStoreInterface $objects;

// Maintenance workflows: outbox reliability, but only the outbox row is transactional.
StandaloneDirectUploadManagerInterface $uploads;
StandaloneObjectStoreInterface $objects;

// Diagnostics and probes: direct provider call, no outbox.
ImmediateDirectUploadManagerInterface $uploads;
ImmediateObjectStoreInterface $objects;
```

## Direct Upload Flow

1. Call `DirectUploadManagerInterface::createUploadIntent()` for a `tmp/...` key.
2. The client uploads directly to R2/S3 using the returned signed URL.
3. Persist domain data with the temporary key.
4. After validation, call `promote()` — copies to a permanent key, optionally deletes the source.

## Server-Side Multipart

`ServerSideMultipartUploadManagerInterface` handles backend-owned transfers: imports, exports, migrations, CLI jobs. It validates S3 part limits, keeps memory bounded by part size, retries transient part failures, and aborts failed uploads. Do not use it for browser uploads — use the direct-to-cloud flow above.

## Lifecycle Provisioning

Lifecycle rules are never applied during HTTP requests, workers, or container boot.

```bash
php bin/console vortos:object-store:lifecycle show
php bin/console vortos:object-store:lifecycle plan
php bin/console vortos:object-store:lifecycle apply --confirm
php bin/console vortos:object-store:lifecycle remove --confirm
```

The managed rule ID defaults to `vortos-object-store-expire-temporary-uploads`. The manager upserts only that rule, preserves unrelated rules, and writes the merged lifecycle config back through the S3 API.

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
