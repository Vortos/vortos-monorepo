<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Plan\PhaseKind;
use Vortos\Deploy\Strategy\BlueGreenStrategy;
use Vortos\Deploy\Strategy\CanaryStrategy;
use Vortos\Deploy\Strategy\DeployStrategyInterface;
use Vortos\Deploy\Strategy\RecreateStrategy;
use Vortos\Deploy\Strategy\RollingStrategy;

final class WorkerPhaseOrderingArchTest extends TestCase
{
    /**
     * @dataProvider strategyProvider
     */
    public function test_roll_workers_precedes_cutover_in_every_strategy(string $strategyClass): void
    {
        $dir = dirname(__DIR__, 2) . '/Strategy';
        $file = $dir . '/' . basename(str_replace('\\', '/', $strategyClass)) . '.php';

        if (!file_exists($file)) {
            $this->markTestSkipped($strategyClass . ' not found at ' . $file);
        }

        $code = (string) file_get_contents($file);

        // If the strategy does not emit RollWorkers, it's fine
        if (!str_contains($code, 'RollWorkers')) {
            $this->addToAssertionCount(1);
            return;
        }

        // Find positions of RollWorkers and Cutover in the source
        $rollPos = strpos($code, 'RollWorkers');
        $cutoverPos = strpos($code, "PhaseKind::Cutover");

        if ($cutoverPos === false) {
            $this->addToAssertionCount(1);
            return;
        }

        $this->assertLessThan(
            $cutoverPos,
            $rollPos,
            sprintf('%s: RollWorkers must appear before Cutover in the strategy source.', $strategyClass),
        );
    }

    /**
     * @dataProvider strategyProvider
     */
    public function test_worker_drain_step_has_no_color_param(string $strategyClass): void
    {
        $dir = dirname(__DIR__, 2) . '/Strategy';
        $file = $dir . '/' . basename(str_replace('\\', '/', $strategyClass)) . '.php';

        if (!file_exists($file)) {
            $this->markTestSkipped($strategyClass . ' not found.');
        }

        $code = (string) file_get_contents($file);

        // Find DrainWorker step blocks — they should not have 'color' param
        if (preg_match_all("/DrainWorker.*?(?=new DeployStep|\\]\s*\\))/s", $code, $matches)) {
            foreach ($matches[0] as $block) {
                $this->assertStringNotContainsString(
                    "'color'",
                    $block,
                    sprintf('%s: DrainWorker step must not carry a color param (workers are rolling-recreate, not blue-green).', $strategyClass),
                );
            }
        }
    }

    /** @return array<string, array{string}> */
    public static function strategyProvider(): array
    {
        return [
            'BlueGreenStrategy' => [BlueGreenStrategy::class],
            'RollingStrategy' => [RollingStrategy::class],
            'RecreateStrategy' => [RecreateStrategy::class],
            'CanaryStrategy' => [CanaryStrategy::class],
        ];
    }
}
