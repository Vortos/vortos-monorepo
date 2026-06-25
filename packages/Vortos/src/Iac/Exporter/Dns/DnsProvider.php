<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Dns;

enum DnsProvider: string
{
    case AwsRoute53 = 'aws-route53';
    case Cloudflare = 'cloudflare';
    case Gcp = 'gcp';
}
