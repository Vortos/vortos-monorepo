<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Lifecycle;

/**
 * A storage class a lifecycle rule may move objects into.
 *
 * Only the classes an object can still be read from synchronously are modelled. Archive tiers
 * (Glacier and friends) need a restore request before a GET succeeds, which would silently break
 * every presigned download of a transitioned object — so they are deliberately not expressible here.
 *
 * The backing value is the S3 wire value; R2 accepts the same string.
 */
enum ObjectStorageClass: string
{
    case InfrequentAccess = 'STANDARD_IA';
}
