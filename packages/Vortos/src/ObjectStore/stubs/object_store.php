<?php

declare(strict_types=1);

use Vortos\ObjectStore\DependencyInjection\VortosObjectStoreConfig;
use Vortos\ObjectStore\Config\ObjectStoreObservabilitySection;

// Large uploads should use direct-to-cloud presigned uploads. The backend
// should issue a short-lived upload URL or POST policy, then persist only the
// final object key in application payloads.
//
// Service guarantees are explicit by injected type:
// - ObjectStoreInterface / DirectUploadManagerInterface:
//     transactional outbox, active CommandBus transaction required.
// - StandaloneObjectStoreInterface / StandaloneDirectUploadManagerInterface:
//     standalone async outbox, opens a short transaction for the outbox row only.
// - ImmediateObjectStoreInterface / ImmediateDirectUploadManagerInterface:
//     direct provider call, no outbox.
//
// ServerSideMultipartUploadManagerInterface is for trusted backend transfers
// such as imports, exports, migrations, and CLI jobs. Public uploads should use
// DirectUploadManagerInterface and presigned direct-to-cloud URLs.

return static function (VortosObjectStoreConfig $config): void {
    $config
        ->driver($_ENV['VORTOS_OBJECT_STORE_DRIVER'] ?? 'log')
        ->provider($_ENV['OBJECT_STORE_PROVIDER'] ?? 'r2')
        ->region($_ENV['OBJECT_STORE_REGION'] ?? 'auto')
        ->endpoint($_ENV['OBJECT_STORE_ENDPOINT'] ?? null)
        ->bucket($_ENV['OBJECT_STORE_BUCKET'] ?? '');

    $config->bucketConfig()
        ->keyPrefix($_ENV['OBJECT_STORE_KEY_PREFIX'] ?? '')
        ->temporaryKeyPrefix($_ENV['OBJECT_STORE_TEMPORARY_KEY_PREFIX'] ?? 'tmp')
        ->publicBaseUrl($_ENV['OBJECT_STORE_PUBLIC_BASE_URL'] ?? null);

    $config->client()
        ->accountId($_ENV['OBJECT_STORE_ACCOUNT_ID'] ?? null)
        ->credentials(
            $_ENV['OBJECT_STORE_ACCESS_KEY_ID'] ?? null,
            $_ENV['OBJECT_STORE_SECRET_ACCESS_KEY'] ?? null,
        )
        ->httpTimeout(10.0)
        ->maxRetries(3);

    // Enabled by default so promotions from tmp/ to permanent keys are reliable.
    // Disable only when you deliberately want synchronous object mutations.
    // $config->outbox()->enabled(false);

    // Trusted server-side multipart transfer tuning. This is not the browser
    // upload path; use it only for backend-owned streams/files.
    // $config->multipart()
    //     ->thresholdBytes(104_857_600)
    //     ->partSizeBytes(16_777_216)
    //     ->maxInlineBodyBytes(16_777_216)
    //     ->maxAttempts(3);
    //
    // Operational cleanup:
    // php bin/console vortos:object-store:multipart abort-stale --older-than="-24 hours" --dry-run

    // Lifecycle provisioning is explicit: run
    // php bin/console vortos:object-store:lifecycle plan
    // php bin/console vortos:object-store:lifecycle apply --confirm
    // It never mutates bucket infrastructure during HTTP requests or boot.
    // $config->lifecycle()->enabled(true)->requireConfirmation(true);

    // Circuit breaker — fast-fail when the provider is unavailable instead of
    // waiting for SDK timeouts. Application exceptions (not found, size limit,
    // policy violation) do not trip the circuit.
    // $config->circuitBreaker()->enabled(true)->failureThreshold(5)->resetTimeoutSeconds(60);

    // Install the outbox relay worker into Docker supervisor:
    // php bin/console vortos:worker:install --worker=object-store-outbox-relay

    // $config->observability()->logging(true)->tracing(true)->metrics(true);
    //
    // $config->observability()
    //     ->disableLoggingFor(ObjectStoreObservabilitySection::Presign)
    //     ->disableTracingFor(ObjectStoreObservabilitySection::DirectUpload)
    //     ->disableMetricsFor(ObjectStoreObservabilitySection::Outbox)
    //     ->disableLoggingFor(ObjectStoreObservabilitySection::Lifecycle);
};
