<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Uptime\Driver\BetterStack;

use PHPUnit\Framework\TestCase;
use Vortos\Health\Uptime\Driver\BetterStack\BetterStackJourneyRenderer;
use Vortos\Health\Uptime\JourneyStep;
use Vortos\Health\Uptime\MonitorDescriptor;
use Vortos\Health\Uptime\SyntheticJourney;

/** Pins the exact rendered payload shape — a renderer-format change must be a deliberate, reviewed diff. */
final class BetterStackJourneyRendererTest extends TestCase
{
    private function descriptor(): MonitorDescriptor
    {
        return new MonitorDescriptor(
            key: 'prod.login-fetch',
            name: 'Login then fetch profile',
            journey: new SyntheticJourney('login-fetch', [
                new JourneyStep('POST', '/login', 200, extractAs: 'token', extractJsonPath: 'data.token'),
                new JourneyStep('GET', '/me', 200, bodyContains: '"email"'),
            ]),
            intervalSeconds: 120,
            regions: ['eu-west', 'us-east'],
            responseTimeSloMs: 1500,
        );
    }

    public function testGoldenVector(): void
    {
        $rendered = (new BetterStackJourneyRenderer())->render($this->descriptor());

        self::assertSame([
            'monitor_type' => 'multistep',
            'pronounceable_name' => 'Login then fetch profile',
            'check_frequency' => 120,
            'regions' => ['eu-west', 'us-east'],
            'request_timeout' => 2,
            'steps' => [
                [
                    'method' => 'POST',
                    'url' => '/login',
                    'expected_status_code' => 200,
                    'extract' => ['as' => 'token', 'json_path' => 'data.token'],
                ],
                [
                    'method' => 'GET',
                    'url' => '/me',
                    'expected_status_code' => 200,
                    'required_keyword' => '"email"',
                ],
            ],
        ], $rendered);
    }

    public function testDefaultsWithoutSloOrRegions(): void
    {
        $descriptor = new MonitorDescriptor(
            'key',
            'name',
            new SyntheticJourney('j', [
                new JourneyStep('POST', '/login', 200),
                new JourneyStep('GET', '/me', 200, bodyContains: 'ok'),
            ]),
        );

        $rendered = (new BetterStackJourneyRenderer())->render($descriptor);

        self::assertSame([], $rendered['regions']);
        self::assertSame(30, $rendered['request_timeout']);
    }

    public function testStepWithoutBodyContainsOrExtractHasOnlyTheBaseFields(): void
    {
        $descriptor = new MonitorDescriptor(
            'key',
            'name',
            new SyntheticJourney('j', [
                new JourneyStep('GET', '/health', 200),
                new JourneyStep('GET', '/me', 200, bodyContains: 'ok'),
            ]),
        );

        $rendered = (new BetterStackJourneyRenderer())->render($descriptor);

        self::assertSame(['method' => 'GET', 'url' => '/health', 'expected_status_code' => 200], $rendered['steps'][0]);
    }
}
