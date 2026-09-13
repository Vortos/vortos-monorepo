<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight;

use Vortos\OpsKit\Gate\GateDisposition;

/**
 * The fail-closed preflight aggregator — the heart of Block 12.
 *
 * Runs every registered {@see PreflightCheckInterface} and assembles a
 * {@see PreflightReport}. The non-negotiable property (§12.9): the doctor *refuses
 * rather than guesses*. A check that throws, a dependency that is missing, an
 * undeterminable answer — all become a 'Fail' finding. There is **no code path that
 * returns a clear report when a Blocking check could not complete**. A check declared Advisory (live
 * runtime state, see {@see \Vortos\OpsKit\Gate\GateDisposition}) that cannot complete is still
 * reported as a failure, carrying its own Advisory disposition — it never becomes a veto it was not.
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
        $disposition = $this->safeDisposition($check);

        try {
            $finding = $check->check($context);
        } catch (\Throwable $e) {
            // Fail-closed: an undeterminable answer is a failure, never a silent pass. It carries the
            // check's own disposition — an advisory check that cannot answer is still only advisory.
            return PreflightFinding::fail(
                $id,
                $category,
                sprintf("check '%s' could not complete", $id),
                $this->describeThrowable($e),
                'Investigate the failing check; it must be able to complete and pass.',
            )->withDisposition($disposition);
        }

        // A finding that narrowed itself (an aggregate reporting only advisory sub-failures) keeps
        // that; everything else takes the check's declaration.
        return $finding->declaredDisposition !== null ? $finding : $finding->withDisposition($disposition);
    }

    /** A check that cannot even say whether it may block is treated as blocking. */
    private function safeDisposition(PreflightCheckInterface $check): GateDisposition
    {
        try {
            return $check->disposition();
        } catch (\Throwable) {
            return GateDisposition::Blocking;
        }
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
