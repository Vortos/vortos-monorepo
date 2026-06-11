<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\ObjectStore;

/**
 * Terraform provider used to manage the object-store bucket.
 *
 *  - Aws: hashicorp/aws — aws_s3_bucket.
 *  - CloudflareR2: cloudflare/cloudflare — cloudflare_r2_bucket.
 */
enum ObjectStoreProvider: string
{
    case Aws = 'aws';
    case CloudflareR2 = 'cloudflare_r2';
}
