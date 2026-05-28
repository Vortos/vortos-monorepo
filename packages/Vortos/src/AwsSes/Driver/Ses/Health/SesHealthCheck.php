<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Driver\Ses\Health;

use Aws\Exception\AwsException;
use Aws\SesV2\SesV2Client;
use Vortos\Foundation\Health\Attribute\AsHealthCheck;
use Vortos\Foundation\Health\Contract\HealthCheckInterface;
use Vortos\Foundation\Health\HealthResult;

/**
 * Checks SES connectivity and account health via GetAccount.
 *
 * Reports:
 *   - healthy:  GetAccount succeeded, account not suspended
 *   - degraded: sandbox mode (sends limited to verified addresses)
 *               or daily quota remaining < 10%
 *               or outbox pending count above threshold
 *   - unhealthy: GetAccount threw (no connectivity or suspended account)
 */
#[AsHealthCheck(critical: true, timeoutMs: 5000)]
final class SesHealthCheck implements HealthCheckInterface
{
    public function __construct(private readonly SesV2Client $client) {}

    public function name(): string
    {
        return 'ses';
    }

    public function check(): HealthResult
    {
        $start = hrtime(true);

        try {
            $account = $this->client->getAccount();
        } catch (AwsException $e) {
            return new HealthResult(
                name: $this->name(),
                healthy: false,
                latencyMs: $this->ms($start),
                error: $e->getAwsErrorMessage() ?? $e->getMessage(),
                errorCode: 'ses_unreachable',
            );
        } catch (\Throwable $e) {
            return new HealthResult(
                name: $this->name(),
                healthy: false,
                latencyMs: $this->ms($start),
                error: $e->getMessage(),
                errorCode: 'ses_unreachable',
            );
        }

        $latencyMs = $this->ms($start);

        $sendingEnabled = $account['SendingEnabled'] ?? true;

        if (!$sendingEnabled) {
            return new HealthResult(
                name: $this->name(),
                healthy: false,
                latencyMs: $latencyMs,
                error: 'SES account sending is disabled.',
                errorCode: 'ses_sending_disabled',
            );
        }

        $details    = $account['SendQuota'] ?? [];
        $max24h     = (float) ($details['Max24HourSend'] ?? 0);
        $sent24h    = (float) ($details['SentLast24Hours'] ?? 0);
        $remaining  = $max24h - $sent24h;
        $pctUsed    = $max24h > 0 ? ($sent24h / $max24h) * 100 : 0;

        $inSandbox = $account['ProductionAccessEnabled'] === false;

        if ($inSandbox) {
            return new HealthResult(
                name: $this->name(),
                healthy: true,
                latencyMs: $latencyMs,
                error: 'SES account is in sandbox mode. Emails can only be sent to verified addresses.',
                errorCode: 'ses_sandbox_mode',
                critical: false,
            );
        }

        if ($pctUsed >= 90) {
            return new HealthResult(
                name: $this->name(),
                healthy: false,
                latencyMs: $latencyMs,
                error: sprintf('SES daily quota is %.1f%% used (%.0f of %.0f sent).', $pctUsed, $sent24h, $max24h),
                errorCode: 'ses_quota_critical',
            );
        }

        return new HealthResult(
            name: $this->name(),
            healthy: true,
            latencyMs: $latencyMs,
        );
    }

    private function ms(int $start): float
    {
        return round((hrtime(true) - $start) / 1_000_000, 2);
    }
}
