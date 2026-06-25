<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight;

/**
 * The fail-closed preflight aggregator — the heart of Block 12.
 *
 * Runs every registered {@see PreflightCheckInterface} and assembles a
 * {@see PreflightReport}. The non-negotiable property (§12.9): the doctor *refuses
 * rather than guesses*. A check that throws, a dependency that is missing, an
 * undeterminable answer — all become a 'Fail' finding. There is **no code path that
 * returns a clear report when a check could not complete**.
 *
 * The same {@see PreflightReport::isClear()} drives both the 'deploy:doctor' exit
 * code and the 'deploy' go/no-go decision, so the gate a human runs is byte-for-byte
 * the gate CI enforces.
 */
final class DeployDoctor
{
    /** @var list<PreflightCheckInterface> */
    private readonly array $checks;

    /**
     * @param iterable<PreflightCheckInterface> $checks
     */
    public function __construct(iterable $checks)
    {
        $this->checks = array_values(
            $checks instanceof \Traversable ? iterator_to_array($checks, false) : $checks,
        );
    }

    public function run(PreflightContext $context, bool $strict = false): PreflightReport
    {
        $findings = [];

        foreach ($this->checks as $check) {
            $findings[] = $this->runCheck($check, $context);
        }

        return new PreflightReport($context->environment->value, $findings, $strict);
    }

    private function runCheck(PreflightCheckInterface $check, PreflightContext $context): PreflightFinding
    {
        $id = $this->safeId($check);
        $category = $this->safeCategory($check);

        try {
            $finding = $check->check($context);
        } catch (\Throwable $e) {
            // Fail-closed: an undeterminable answer is a refusal, never a silent pass.
            return PreflightFinding::fail(
                $id,
                $category,
                sprintf("check '%s' could not complete", $id),
                $this->describeThrowable($e),
                'Investigate the failing check; the deploy is refused until it can complete and pass.',
            );
        }

        return $finding;
    }

    private function safeId(PreflightCheckInterface $check): string
    {
        try {
            $id = $check->id();
        } catch (\Throwable) {
            return $check::class;
        }

        return $id === '' ? $check::class : $id;
    }

    private function safeCategory(PreflightCheckInterface $check): PreflightCategory
    {
        try {
            return $check->category();
        } catch (\Throwable) {
            return PreflightCategory::Plan;
        }
    }

    private function describeThrowable(\Throwable $e): string
    {
        $type = (new \ReflectionClass($e))->getShortName();

        return sprintf('%s: %s', $type, $e->getMessage());
    }
}
