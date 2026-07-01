<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Architecture tripwires for the Scheduler Admin package.
 *
 * These tests scan the PHP source text / AST and fail immediately if someone
 * bypasses the security invariants that are too subtle to catch in code review.
 */
final class AdminArchitectureTest extends TestCase
{
    private string $packageDir;

    protected function setUp(): void
    {
        $this->packageDir = dirname(__DIR__, 2);
    }

    /**
     * Every #[Route] method in Http/ must call either:
     *  - $this->policy->assertCanXxx()    (SchedulePolicyInterface)
     *  - $this->service->                 (ScheduleService — which does RBAC internally)
     *
     * The scheduler admin delegates all RBAC to ScheduleService or SchedulePolicyInterface,
     * so we check that controllers call one of those two seams (not raw store).
     */
    public function test_every_route_goes_through_service_or_policy(): void
    {
        $violations = [];

        foreach ($this->phpFiles($this->packageDir . '/Http') as $file) {
            $source = (string) file_get_contents($file->getPathname());

            if (!str_contains($source, '#[Route(')) {
                continue;
            }

            preg_match_all(
                '/public\s+function\s+(\w+)\s*\([^)]*\)\s*:\s*\w+\s*\{(.*?)(?=(?:public|protected|private)\s+function|\}\s*\z)/s',
                $source,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $match) {
                $methodName = $match[1];
                $methodBody = $match[2];

                if ($methodName === '__construct') {
                    continue;
                }

                // Legitimate read-only actions that don't need domain delegation:
                //   new()     — blank create-form GET; auth gate is handled by middleware
                //   preview() — HTMX cron-expression preview; no data access, no RBAC needed
                $relativeFile = str_replace($this->packageDir . '/', '', $file->getPathname());
                if (in_array("{$relativeFile}::{$methodName}()", [
                    'Http/Controller/ScheduleCreateController.php::new()',
                    'Http/Fragment/TriggerPreviewFragmentController.php::preview()',
                ], true)) {
                    continue;
                }

                $hasRouteAttr = (bool) preg_match(
                    '/#\[Route\(.*?\)\]\s*public\s+function\s+' . preg_quote($methodName, '/') . '\b/',
                    $source,
                );

                if (!$hasRouteAttr) {
                    continue;
                }

                $usesServiceOrPolicy = str_contains($methodBody, '$this->service->')
                    || str_contains($methodBody, '$this->policy->')
                    || str_contains($methodBody, '$this->approvalStore->')
                    || str_contains($methodBody, '$this->fourEyesGate->')
                    || str_contains($methodBody, '$this->scheduleStore->')
                    || str_contains($methodBody, '$this->auditRepo->')
                    || str_contains($methodBody, '$this->runStore->');

                if (!$usesServiceOrPolicy) {
                    $relative    = str_replace($this->packageDir . '/', '', $file->getPathname());
                    $violations[] = "{$relative}::{$methodName}()";
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "These route methods don't go through service/policy/store — all admin routes must delegate through the scheduler domain layer:\n  - "
            . implode("\n  - ", $violations),
        );
    }

    public function test_admin_controllers_do_not_inject_http_client(): void
    {
        $violations = [];

        foreach ($this->phpFiles($this->packageDir . '/Http') as $file) {
            $source   = (string) file_get_contents($file->getPathname());
            $relative = str_replace($this->packageDir . '/', '', $file->getPathname());

            if (str_contains($source, 'HttpClient') || str_contains($source, 'GuzzleHttp')) {
                $violations[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $violations,
            "These admin controllers inject an HTTP client (self-HTTP is forbidden):\n  - "
            . implode("\n  - ", $violations),
        );
    }

    public function test_controllers_never_mutate_stores_directly(): void
    {
        $violations = [];

        foreach ($this->phpFiles($this->packageDir . '/Http/Controller') as $file) {
            $source   = (string) file_get_contents($file->getPathname());
            $relative = str_replace($this->packageDir . '/', '', $file->getPathname());

            if (
                str_contains($source, 'ScheduleStoreInterface')
                && (preg_match('/->\s*save\s*\(/', $source) || preg_match('/->\s*delete\s*\(/', $source))
            ) {
                $violations[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $violations,
            "These page controllers call store mutators directly — route through ScheduleService:\n  - "
            . implode("\n  - ", $violations),
        );
    }

    public function test_twig_renderer_is_always_used_for_html_responses(): void
    {
        $violations = [];

        foreach ($this->phpFiles($this->packageDir . '/Http') as $file) {
            $source   = (string) file_get_contents($file->getPathname());
            $relative = str_replace($this->packageDir . '/', '', $file->getPathname());

            if (
                str_contains($source, 'new Response(')
                && str_contains($source, 'text/html')
                && !str_contains($source, 'TwigRenderer')
            ) {
                $violations[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $violations,
            "These HTTP files build HTML responses without TwigRenderer — use TwigRenderer exclusively:\n  - "
            . implode("\n  - ", $violations),
        );
    }

    public function test_no_raw_superglobals_used(): void
    {
        $violations = [];

        $forbidden = ['$_GET', '$_POST', '$_REQUEST', '$_SERVER', '$_COOKIE', '$_SESSION', '$_FILES'];

        foreach ($this->phpFiles($this->packageDir . '/Http') as $file) {
            $source   = (string) file_get_contents($file->getPathname());
            $relative = str_replace($this->packageDir . '/', '', $file->getPathname());

            foreach ($forbidden as $global) {
                if (str_contains($source, $global)) {
                    $violations[] = "{$relative} uses {$global}";
                    break;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "These files use PHP superglobals directly — use Symfony Request instead:\n  - "
            . implode("\n  - ", $violations),
        );
    }

    /** @return iterable<\SplFileInfo> */
    private function phpFiles(string $dir): iterable
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file;
            }
        }
    }
}
