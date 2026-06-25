<?php

declare(strict_types=1);

namespace Vortos\Observability\Query\Driver;

use Vortos\Observability\Query\Capability\MetricsQueryCapability;
use Vortos\Observability\Query\MetricQuery;
use Vortos\Observability\Query\MetricsQueryInterface;
use Vortos\Observability\Query\QueryResult;
use Vortos\Observability\Query\QuerySample;
use Vortos\Observability\Query\QuerySeries;
use Vortos\Observability\Query\QueryWindow;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('slo-prometheus')]
final class PrometheusMetricsQuery implements MetricsQueryInterface
{
    private const DENIED_RANGES = [
        ['0.0.0.0', 8],
        ['10.0.0.0', 8],
        ['100.64.0.0', 10],
        ['127.0.0.0', 8],
        ['169.254.0.0', 16],
        ['172.16.0.0', 12],
        ['192.0.0.0', 24],
        ['192.168.0.0', 16],
        ['198.18.0.0', 15],
        ['224.0.0.0', 4],
        ['::1', 128],
        ['::', 128],
        ['::ffff:0:0', 96],
        ['fc00::', 7],
        ['fe80::', 10],
        ['ff00::', 8],
    ];

    /** @var callable|null */
    private $transport;

    private readonly string $host;
    private readonly int $port;

    /** @var (\Closure(string):list<string>)|null */
    private readonly ?\Closure $resolver;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $bearerToken = '',
        private readonly int $timeoutSeconds = 10,
        private readonly int $maxResponseBodyBytes = 1_048_576,
        ?callable $transport = null,
        ?\Closure $resolver = null,
    ) {
        $this->transport = $transport;
        $this->resolver = $resolver;

        $parsed = parse_url($baseUrl);
        if (!is_array($parsed) || !isset($parsed['host'])) {
            throw new \InvalidArgumentException(sprintf('Invalid Prometheus base URL: "%s".', $baseUrl));
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        if ($scheme !== 'https') {
            throw new \InvalidArgumentException(sprintf(
                'PrometheusMetricsQuery requires HTTPS; got scheme "%s". Plain HTTP is rejected (SSRF hardening).',
                $scheme,
            ));
        }

        $this->host = $parsed['host'];
        $this->port = $parsed['port'] ?? 443;

        if (filter_var($this->host, \FILTER_VALIDATE_IP) !== false) {
            foreach (self::DENIED_RANGES as [$network, $prefix]) {
                if ($this->inRange($this->host, $network, $prefix)) {
                    throw new \InvalidArgumentException(sprintf(
                        'PrometheusMetricsQuery: IP literal %s is in a denied range. Request blocked (SSRF guard).',
                        $this->host,
                    ));
                }
            }
        }
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            MetricsQueryCapability::InstantQuery->value => true,
            MetricsQueryCapability::RangeQuery->value => true,
            MetricsQueryCapability::Quantiles->value => true,
            MetricsQueryCapability::LabelFilter->value => true,
        ]);
    }

    public function instant(MetricQuery $q): QueryResult
    {
        $now = new \DateTimeImmutable();
        $url = rtrim($this->baseUrl, '/') . '/api/v1/query?' . http_build_query([
            'query' => $q->toPromQL(),
            'time' => $now->getTimestamp(),
        ]);

        try {
            $body = $this->httpGet($url);
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

            if (($data['status'] ?? '') !== 'success') {
                return QueryResult::empty($now);
            }

            $results = $data['data']['result'] ?? [];
            if (!is_array($results) || $results === []) {
                return QueryResult::empty($now);
            }

            $value = (float) ($results[0]['value'][1] ?? \NAN);
            if (is_nan($value)) {
                return QueryResult::empty($now);
            }

            return new QueryResult($value, 1, $now);
        } catch (\Throwable) {
            return QueryResult::empty($now);
        }
    }

    public function range(MetricQuery $q, QueryWindow $w): QuerySeries
    {
        $now = time();
        $url = rtrim($this->baseUrl, '/') . '/api/v1/query_range?' . http_build_query([
            'query' => $q->toPromQL(),
            'start' => $w->startTimestamp($now),
            'end' => $now,
            'step' => $w->stepSeconds,
        ]);

        try {
            $body = $this->httpGet($url);
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

            if (($data['status'] ?? '') !== 'success') {
                return QuerySeries::empty();
            }

            $results = $data['data']['result'] ?? [];
            if (!is_array($results) || $results === []) {
                return QuerySeries::empty();
            }

            $rawValues = $results[0]['values'] ?? [];
            if (!is_array($rawValues) || $rawValues === []) {
                return QuerySeries::empty();
            }

            $samples = [];
            foreach ($rawValues as [$ts, $val]) {
                $float = (float) $val;
                if (!is_nan($float)) {
                    $samples[] = new QuerySample($float, (int) $ts);
                }
            }

            return new QuerySeries($samples);
        } catch (\Throwable) {
            return QuerySeries::empty();
        }
    }

    private function httpGet(string $url): string
    {
        if ($this->transport !== null) {
            return ($this->transport)($url);
        }

        if (!function_exists('curl_init')) {
            throw new \RuntimeException('curl extension is required for PrometheusMetricsQuery.');
        }

        $safeIp = $this->resolveAndValidate($this->host);

        $ch = curl_init();
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialise curl handle.');
        }

        $headers = ['Accept: application/json'];
        if ($this->bearerToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->bearerToken;
        }

        curl_setopt_array($ch, [
            \CURLOPT_URL => $url,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT => $this->timeoutSeconds,
            \CURLOPT_CONNECTTIMEOUT => min(5, $this->timeoutSeconds),
            \CURLOPT_HTTPHEADER => $headers,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
            \CURLOPT_MAXFILESIZE => $this->maxResponseBodyBytes,
            \CURLOPT_RESOLVE => [
                sprintf('%s:%d:%s', $this->host, $this->port, $safeIp),
            ],
        ]);

        try {
            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($ch);
        }

        if ($body === false || $httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException(sprintf('Prometheus query returned HTTP %d.', $httpCode));
        }

        if (strlen((string) $body) > $this->maxResponseBodyBytes) {
            throw new \RuntimeException('Prometheus response exceeds max allowed size.');
        }

        return (string) $body;
    }

    private function resolveAndValidate(string $host): string
    {
        if (filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            $this->assertIpSafe($host);
            return $host;
        }

        $ips = $this->resolveIps($host);

        if ($ips === []) {
            throw new \RuntimeException(sprintf(
                'PrometheusMetricsQuery: host "%s" could not be resolved — fail-closed (SSRF guard).',
                $host,
            ));
        }

        foreach ($ips as $ip) {
            $this->assertIpSafe($ip);
        }

        return $ips[0];
    }

    /** @return list<string> */
    private function resolveIps(string $host): array
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($host);
        }

        $ips = [];
        $records = @dns_get_record($host, \DNS_A | \DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                } elseif (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $resolved = gethostbyname($host);
            if ($resolved !== $host) {
                $ips[] = $resolved;
            }
        }

        return $ips;
    }

    private function assertIpSafe(string $ip): void
    {
        foreach (self::DENIED_RANGES as [$network, $prefix]) {
            if ($this->inRange($ip, $network, $prefix)) {
                throw new \RuntimeException(sprintf(
                    'PrometheusMetricsQuery: resolved IP %s is in a denied range. Request blocked (SSRF guard).',
                    $ip,
                ));
            }
        }
    }

    private function inRange(string $ip, string $network, int $prefix): bool
    {
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($network);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }

        $bytes = intdiv($prefix, 8);
        $bits = $prefix % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $bits)) & 0xFF);

        return (chr(ord($ipBin[$bytes]) & ord($mask))) === (chr(ord($netBin[$bytes]) & ord($mask)));
    }
}
