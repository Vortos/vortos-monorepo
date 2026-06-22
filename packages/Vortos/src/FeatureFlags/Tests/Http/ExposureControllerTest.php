<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Http;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Exposure\ExposureIngestService;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\Http\ExposureController;
use Vortos\FeatureFlags\Http\FlagContextResolverInterface;
use Vortos\FeatureFlags\Metrics\FlagEvaluationMetrics;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\FeatureFlags\Tests\Support\RecordingMetrics;
use Vortos\Http\Request;

final class ExposureControllerTest extends TestCase
{
    public function test_single_exposure_is_accepted(): void
    {
        [$controller, $sink] = $this->controller(['checkout']);

        $response = $controller(
            $this->request(json_encode(['name' => 'checkout', 'variant' => 'b', 'timestamp' => 1])),
        );

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame(1, $this->body($response)['accepted']);
        $this->assertCount(1, $sink->counters);
    }

    public function test_batch_exposures_accepted_and_unknown_dropped(): void
    {
        [$controller, $sink] = $this->controller(['a', 'b']);

        $response = $controller($this->request(json_encode([
            ['name' => 'a', 'variant' => null, 'timestamp' => 1],
            ['name' => 'b', 'variant' => 'x', 'timestamp' => 2],
            ['name' => 'evil-unknown', 'timestamp' => 3],
        ])));

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame(2, $this->body($response)['accepted']);
    }

    public function test_invalid_json_is_rejected(): void
    {
        [$controller] = $this->controller(['a']);

        $response = $controller($this->request('{not json'));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_oversized_body_is_rejected(): void
    {
        [$controller] = $this->controller(['a']);

        $response = $controller($this->request(str_repeat('x', 256 * 1024 + 1)));

        $this->assertSame(413, $response->getStatusCode());
    }

    public function test_malformed_items_are_skipped(): void
    {
        [$controller] = $this->controller(['a']);

        $response = $controller($this->request(json_encode([
            ['variant' => 'no-name'],   // missing name → skipped
            ['name' => '', 'variant' => 'blank'], // blank name → skipped
            ['name' => 'a'],            // valid
        ])));

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame(1, $this->body($response)['accepted']);
    }

    /**
     * @param list<string> $flagNames
     * @return array{0: ExposureController, 1: RecordingMetrics}
     */
    private function controller(array $flagNames): array
    {
        $now   = new \DateTimeImmutable();
        $flags = array_map(
            static fn(string $n) => new FeatureFlag('id-' . $n, $n, '', true, [], null, $now, $now),
            $flagNames,
        );

        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findAll')->willReturn($flags);

        $sink    = new RecordingMetrics();
        $ingest  = new ExposureIngestService($storage, new FlagEvaluationMetrics($sink));

        $resolver = new class implements FlagContextResolverInterface {
            public function resolve(Request $request): FlagContext
            {
                return new FlagContext('user-1');
            }
        };

        return [new ExposureController($ingest, $resolver), $sink];
    }

    private function request(string $content): Request
    {
        return Request::create('/api/flags/exposures', 'POST', [], [], [], [], $content);
    }

    /** @return array<string,mixed> */
    private function body(object $response): array
    {
        return json_decode($response->getContent(), true);
    }
}
