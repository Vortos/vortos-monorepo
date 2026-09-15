<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Topology;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Model\ComposeServiceSet;
use Vortos\Pipeline\Topology\ImageProvenanceRule;
use Vortos\Pipeline\Topology\TopologyPolicy;
use Vortos\Pipeline\Topology\TopologyViolation;

/** RC-5 guard: an application-repository digest runs only where a pinned image declares it. */
final class ImageProvenancePinnedImageTest extends TestCase
{
    private const D = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const REPO = 'docker.io/acme/app';

    private function yaml(string $writeDb = self::REPO . '@sha256:' . self::D, string $redis = 'redis:alpine@sha256:' . self::D): string
    {
        return "services:\n  write_db:\n    image: {$writeDb}\n  redis:\n    image: {$redis}\n";
    }

    /** @return list<string> */
    private function kinds(string $yaml, ?string $repository = self::REPO): array
    {
        $policy = new TopologyPolicy('/opt/vortos', [new ImageProvenanceRule([], $repository, ['postgres' => new ComposeServiceSet(['write_db'])])]);

        return array_map(static fn (TopologyViolation $v): string => $v->kind->value . ':' . $v->service, $policy->evaluate($yaml));
    }

    public function test_the_declared_service_on_an_application_repository_digest_is_clear(): void
    {
        self::assertSame([], $this->kinds($this->yaml()));
    }

    public function test_a_tag_in_front_of_the_digest_is_still_the_application_repository(): void
    {
        self::assertSame([], $this->kinds($this->yaml(writeDb: self::REPO . ':pinned-postgres-abc@sha256:' . self::D)));
    }

    public function test_an_application_repository_digest_on_an_undeclared_service_is_refused(): void
    {
        self::assertSame(['undeclared_own_image:redis'], $this->kinds($this->yaml(redis: self::REPO . '@sha256:' . self::D)));
    }

    public function test_a_declared_pinned_service_not_running_an_application_repository_digest_is_unconsumed(): void
    {
        self::assertSame(['unconsumed:write_db'], $this->kinds($this->yaml(writeDb: 'postgres:18-alpine@sha256:' . self::D)));
    }

    public function test_a_registry_port_is_part_of_the_repository_not_a_tag(): void
    {
        $repo = 'registry.local:5000/acme/app';

        self::assertSame([], $this->kinds($this->yaml(writeDb: $repo . '@sha256:' . self::D), $repo));
        self::assertSame(['unconsumed:write_db'], $this->kinds($this->yaml(writeDb: 'registry.local:5000/acme/other@sha256:' . self::D), $repo));
    }
}
