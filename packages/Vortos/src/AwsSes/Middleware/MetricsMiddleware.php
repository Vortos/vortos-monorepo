<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Middleware;

use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\AwsSes\Attribute\AsEmailMiddleware;
use Vortos\AwsSes\Config\AwsSesObservabilitySection;
use Vortos\AwsSes\Contract\EmailMiddlewareInterface;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

#[AsEmailMiddleware(priority: 650)]
final class MetricsMiddleware implements EmailMiddlewareInterface
{
    /** @param string[] $disabledSections */
    public function __construct(
        private readonly MetricsInterface $metrics,
        private readonly array $disabledSections = [],
    ) {}

    public function process(Email $email, callable $next): SentEmail
    {
        if (in_array(AwsSesObservabilitySection::Send->value, $this->disabledSections, true)) {
            return $next($email);
        }

        $start = hrtime(true);

        try {
            $result = $next($email);
            $this->metrics->counter('vortos_aws_ses_send_total', [
                'driver' => $result->driver(),
                'status' => 'success',
            ])->increment();
            return $result;
        } catch (\Throwable $e) {
            $this->metrics->counter('vortos_aws_ses_send_total', [
                'driver' => 'unknown',
                'status' => 'failure',
            ])->increment();
            throw $e;
        } finally {
            $this->metrics->histogram('vortos_aws_ses_send_duration_ms')->observe(
                round((hrtime(true) - $start) / 1_000_000, 2),
            );
        }
    }
}
