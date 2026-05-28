<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Config\AwsSesObservabilitySection;
use Vortos\AwsSes\DependencyInjection\VortosAwsSesConfig;

final class VortosAwsSesConfigTest extends TestCase
{
    public function test_to_array_contains_all_top_level_keys(): void
    {
        $config = new VortosAwsSesConfig();
        $array  = $config->toArray();

        $this->assertArrayHasKey('driver', $array);
        $this->assertArrayHasKey('region', $array);
        $this->assertArrayHasKey('fallback_region', $array);
        $this->assertArrayHasKey('default_from_address', $array);
        $this->assertArrayHasKey('default_from_name', $array);
        $this->assertArrayHasKey('reply_to', $array);
        $this->assertArrayHasKey('configuration_set', $array);
        $this->assertArrayHasKey('aws_client', $array);
        $this->assertArrayHasKey('outbox', $array);
        $this->assertArrayHasKey('webhooks', $array);
        $this->assertArrayHasKey('suppression', $array);
        $this->assertArrayHasKey('rate_limit', $array);
        $this->assertArrayHasKey('audit_log', $array);
        $this->assertArrayHasKey('circuit_breaker', $array);
        $this->assertArrayHasKey('observability', $array);
    }

    public function test_fluent_methods_return_same_instance(): void
    {
        $config = new VortosAwsSesConfig();

        $this->assertSame($config, $config->driver('log'));
        $this->assertSame($config, $config->region('us-east-1'));
        $this->assertSame($config, $config->fallbackRegion(null));
        $this->assertSame($config, $config->defaultFrom('a@b.com'));
        $this->assertSame($config, $config->replyTo(null));
        $this->assertSame($config, $config->configurationSet(null));
    }

    public function test_sub_config_builders_return_same_instance(): void
    {
        $config = new VortosAwsSesConfig();

        $outbox = $config->outbox();
        $this->assertSame($outbox, $config->outbox());

        $awsClient = $config->awsClient();
        $this->assertSame($awsClient, $config->awsClient());

        $webhooks = $config->webhooks();
        $this->assertSame($webhooks, $config->webhooks());

        $suppression = $config->suppression();
        $this->assertSame($suppression, $config->suppression());

        $rateLimit = $config->rateLimit();
        $this->assertSame($rateLimit, $config->rateLimit());

        $auditLog = $config->auditLog();
        $this->assertSame($auditLog, $config->auditLog());

        $circuitBreaker = $config->circuitBreaker();
        $this->assertSame($circuitBreaker, $config->circuitBreaker());

        $observability = $config->observability();
        $this->assertSame($observability, $config->observability());
    }

    public function test_observability_section_opt_outs_are_typed(): void
    {
        $config = new VortosAwsSesConfig();
        $config->observability()
            ->disableLoggingFor(AwsSesObservabilitySection::Send)
            ->disableTracingFor(AwsSesObservabilitySection::Outbox)
            ->disableMetricsFor(AwsSesObservabilitySection::Webhook);

        $array = $config->toArray();

        $this->assertSame([AwsSesObservabilitySection::Send->value], $array['observability']['logging_disabled_for']);
        $this->assertSame([AwsSesObservabilitySection::Outbox->value], $array['observability']['tracing_disabled_for']);
        $this->assertSame([AwsSesObservabilitySection::Webhook->value], $array['observability']['metrics_disabled_for']);
    }

    public function test_env_var_VORTOS_MAILER_DRIVER_sets_driver(): void
    {
        $prev = $_ENV['VORTOS_MAILER_DRIVER'] ?? null;

        try {
            $_ENV['VORTOS_MAILER_DRIVER'] = 'ses';
            $config = new VortosAwsSesConfig();
            $this->assertSame('ses', $config->toArray()['driver']);
        } finally {
            if ($prev === null) {
                unset($_ENV['VORTOS_MAILER_DRIVER']);
            } else {
                $_ENV['VORTOS_MAILER_DRIVER'] = $prev;
            }
        }
    }

    public function test_env_var_AWS_SES_REGION_sets_region(): void
    {
        $prev = $_ENV['AWS_SES_REGION'] ?? null;

        try {
            $_ENV['AWS_SES_REGION'] = 'eu-central-1';
            $config = new VortosAwsSesConfig();
            $this->assertSame('eu-central-1', $config->toArray()['region']);
        } finally {
            if ($prev === null) {
                unset($_ENV['AWS_SES_REGION']);
            } else {
                $_ENV['AWS_SES_REGION'] = $prev;
            }
        }
    }

    public function test_env_var_AWS_ENDPOINT_sets_endpoint_override(): void
    {
        $prev = $_ENV['AWS_ENDPOINT'] ?? null;

        try {
            $_ENV['AWS_ENDPOINT'] = 'http://localstack:4566';
            $config = new VortosAwsSesConfig();
            $this->assertSame('http://localstack:4566', $config->toArray()['aws_client']['endpoint_override']);
        } finally {
            if ($prev === null) {
                unset($_ENV['AWS_ENDPOINT']);
            } else {
                $_ENV['AWS_ENDPOINT'] = $prev;
            }
        }
    }

    public function test_explicit_driver_overrides_env_var(): void
    {
        $prev = $_ENV['VORTOS_MAILER_DRIVER'] ?? null;

        try {
            $_ENV['VORTOS_MAILER_DRIVER'] = 'ses';
            $config = new VortosAwsSesConfig();
            $config->driver('null');
            $this->assertSame('null', $config->toArray()['driver']);
        } finally {
            if ($prev === null) {
                unset($_ENV['VORTOS_MAILER_DRIVER']);
            } else {
                $_ENV['VORTOS_MAILER_DRIVER'] = $prev;
            }
        }
    }
}
