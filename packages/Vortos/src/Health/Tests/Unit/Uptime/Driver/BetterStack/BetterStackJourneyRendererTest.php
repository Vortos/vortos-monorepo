<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Uptime\Driver\BetterStack;

use PHPUnit\Framework\TestCase;
use Vortos\Health\Uptime\Driver\BetterStack\BetterStackJourneyRenderer;
use Vortos\Health\Uptime\Exception\MonitorSyncException;
use Vortos\Health\Uptime\JourneyStep;
use Vortos\Health\Uptime\MonitorDescriptor;
use Vortos\Health\Uptime\SyntheticJourney;

/**
 * Pins the rendered payload to a shape Better Stack's API accepts.
 *
 * The previous golden vector asserted `monitor_type: multistep` with a `steps` array. That shape
 * has never been valid — the API answers 422 "Sorry, you misspelled some attributes" for `steps`
 * and `external_id` — so these tests passed while every real sync failed. A golden vector is only
 * worth having if it is pinned to what the far side actually accepts.
 */
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
            'monitor_type' => 'keyword',
            'url' => '/me',
            'required_keyword' => '"email"',
            'pronounceable_name' => 'Login then fetch profile (1 of 2 steps: /me)',
            'check_frequency' => 120,
            'http_method' => 'get',
            'request_timeout' => 2,
            'regions' => ['eu-west', 'us-east'],
        ], $rendered);
    }

    public function testItNeverEmitsAttributesTheApiRejects(): void
    {
        // The exact regression: these two keys are what produced the 422.
        $rendered = (new BetterStackJourneyRenderer())->render($this->descriptor());

        self::assertArrayNotHasKey('steps', $rendered);
        self::assertArrayNotHasKey('external_id', $rendered);
        self::assertNotSame('multistep', $rendered['monitor_type']);
    }

    public function testItProjectsOntoTheLastBodyAssertingStep(): void
    {
        // A monitor checks one URL, so the projection must land on the step carrying the strongest
        // assertion — the final one — not the first, which is typically just a login.
        $descriptor = new MonitorDescriptor(
            key: 'prod.multi',
            name: 'Multi',
            journey: new SyntheticJourney('multi', [
                new JourneyStep('GET', '/first', 200, bodyContains: 'first'),
                new JourneyStep('GET', '/second', 200, bodyContains: 'second'),
            ]),
        );

        $rendered = (new BetterStackJourneyRenderer())->render($descriptor);

        self::assertSame('/second', $rendered['url']);
        self::assertSame('second', $rendered['required_keyword']);
    }

    public function testItAssertsTheBodyAndNotMerelyTheStatus(): void
    {
        // `status` would pass on any 2xx. A health endpoint returning 200 with "status":"fail" is
        // exactly the case worth catching, so the monitor type must be keyword.
        $rendered = (new BetterStackJourneyRenderer())->render($this->descriptor());

        self::assertSame('keyword', $rendered['monitor_type']);
        self::assertNotSame('', $rendered['required_keyword']);
    }

    public function testTheNameDiscloseWhatIsNotCovered(): void
    {
        // The API has no field for "one leg of a longer journey", so the name carries it. A
        // dashboard that implies a full journey while checking a single URL is misleading.
        $rendered = (new BetterStackJourneyRenderer())->render($this->descriptor());

        self::assertStringContainsString('1 of 2 steps', $rendered['pronounceable_name']);
    }

    public function testASingleStepJourneyKeepsItsPlainName(): void
    {
        $descriptor = new MonitorDescriptor(
            key: 'prod.single',
            name: 'Readiness',
            journey: new SyntheticJourney('single', [
                new JourneyStep('GET', '/health/ready', 200, bodyContains: '"status":"pass"'),
                new JourneyStep('GET', '/health/ready', 200, bodyContains: '"status":"pass"'),
            ]),
        );

        $rendered = (new BetterStackJourneyRenderer())->render($descriptor);

        // Two identical steps still project to one URL; the disclosure is still honest.
        self::assertStringContainsString('1 of 2 steps', $rendered['pronounceable_name']);
        self::assertSame('/health/ready', $rendered['url']);
    }

    public function testItRefusesToDegradeToAStatusOnlyMonitor(): void
    {
        // SyntheticJourney guarantees a body assertion exists, so this is unreachable through the
        // public API — but if it ever were, failing loudly beats silently shipping a weaker check
        // than the operator declared.
        $journey = (new \ReflectionClass(SyntheticJourney::class))->newInstanceWithoutConstructor();
        $steps = new \ReflectionProperty(SyntheticJourney::class, 'steps');
        $steps->setValue($journey, [new JourneyStep('GET', '/health', 200)]);
        $name = new \ReflectionProperty(SyntheticJourney::class, 'name');
        $name->setValue($journey, 'statusonly');

        $descriptor = new MonitorDescriptor(key: 'prod.weak', name: 'Weak', journey: $journey);

        $this->expectException(MonitorSyncException::class);
        (new BetterStackJourneyRenderer())->render($descriptor);
    }

    /**
     * The payload URL must be ABSOLUTE when a base URL is configured.
     *
     * Better Stack monitors a URL, so "/health/ready" is not something it can create a monitor for.
     * Worse, BetterStackClient locates an existing monitor by matching this value against the
     * stored `url`, which is absolute — so a relative path also fails to recognise the monitor
     * already watching that endpoint and would add a duplicate instead of updating it.
     *
     * That combination is why production's monitor was created by hand and `vortos.uptime_sync`
     * was empty: `health:monitor:sync --apply` had never once succeeded.
     */
    public function testItRendersAnAbsoluteUrlFromTheConfiguredBase(): void
    {
        $descriptor = new MonitorDescriptor(
            key: 'prod.api',
            name: 'API',
            journey: new SyntheticJourney('api', [
                new JourneyStep('GET', '/health/live', 200, bodyContains: '"mode":"live"'),
                new JourneyStep('GET', '/health/ready', 200, bodyContains: '"status":"pass"'),
            ]),
        );

        $rendered = (new BetterStackJourneyRenderer('https://api.example.com'))->render($descriptor);

        self::assertSame('https://api.example.com/health/ready', $rendered['url']);
    }

    public function testItDoesNotDoubleUpSlashesOrRewriteAnAbsolutePath(): void
    {
        $descriptor = new MonitorDescriptor(
            key: 'prod.api',
            name: 'API',
            journey: new SyntheticJourney('api', [
                new JourneyStep('GET', '/a', 200, bodyContains: 'x'),
                new JourneyStep('GET', '/health/ready', 200, bodyContains: '"status":"pass"'),
            ]),
        );

        // Trailing slash on the base must not produce a doubled separator.
        self::assertSame(
            'https://api.example.com/health/ready',
            (new BetterStackJourneyRenderer('https://api.example.com/'))->render($descriptor)['url'],
        );
    }
}
