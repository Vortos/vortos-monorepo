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

`apply` brings the bucket to the declared rule set and is idempotent: when nothing drifted it writes nothing, so it is safe to run on every deploy. `plan` exits `2` when there are changes, `0` when up to date.

### Declaring rules

```php
use Vortos\ObjectStore\Lifecycle\LifecycleRule;
use Vortos\ObjectStore\Lifecycle\ObjectStorageClass;

$config->lifecycle()
    ->enabled(true)
    ->requireConfirmation(true)
    ->rule(LifecycleRule::transitionAfter('vortos-app-submissions-ia', 'submissions', 90, ObjectStorageClass::InfrequentAccess))
    ->rule(LifecycleRule::expireAfter('vortos-app-exports-expire', 'exports', 7));
```

- **Ownership is by rule-ID namespace** (`managedRuleIdPrefix()`, default `vortos-`). Every rule whose ID starts with it is managed: a managed rule removed from config is removed from the bucket on the next `apply`. Rules outside the namespace (console-made rules, the provider's default multipart-abort rule) are never touched. Declaring a rule outside the namespace fails the container build.
- **The temporary-upload expiry rule** (`rule_id`, default `vortos-object-store-expire-temporary-uploads`) is built from `bucket.temporary_key_prefix` and `orphan_ttl_seconds` while `manageTemporaryUploads(true)`. With it off, that ID is treated as unowned and left in place.
- **Every rule needs a non-empty prefix.** A bucket-wide rule would also act on temporary uploads and public assets.
- **Rules are compared as models**, not raw arrays, so a provider's key order or legacy `Prefix` field does not read as drift. A managed rule the model cannot represent exactly (date expiry, tag filter, archive class) is rewritten to the declared shape.
- Declarations are validated when config loads (even with lifecycle disabled), and again at `plan` against provider capabilities.

### Storage-class transitions

Only `ObjectStorageClass::InfrequentAccess` (`STANDARD_IA`) is supported. Archive tiers are deliberately excluded: their objects cannot be read through a presigned GET until restored.

| Provider | Transitions | Earliest transition |
|---|---|---|
| `r2` | yes | 1 day — but Infrequent Access bills a **30-day minimum storage duration** and a per-GB retrieval fee; lifecycle cannot move objects back to Standard |
| `aws_s3`, `s3` | yes | 30 days (AWS refuses earlier) |
| `generic_s3` | no | — `plan` fails |

Do not transition prefixes whose objects are deleted within 30 days or read constantly (public assets, support attachments): the minimum duration and retrieval fees cost more than Standard.

Managing lifecycle needs write access to the bucket configuration — on R2 an API token with bucket-level **Workers R2 Storage Write**. Check the token before making `apply` part of a deploy.

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
