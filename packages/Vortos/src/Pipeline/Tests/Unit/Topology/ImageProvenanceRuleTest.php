<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Topology;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Model\ComposeServiceSet;
use Vortos\Pipeline\Topology\ImageProvenanceRule;
use Vortos\Pipeline\Topology\TopologyPolicy;
use Vortos\Pipeline\Topology\TopologyViolation;

/** RC-9 guard: every image a production topology runs is provably the reviewed bytes. */
final class ImageProvenanceRuleTest extends TestCase
{
    private const D = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private function clear(): string
    {
        $d = self::D;

        return <<<YAML
            services:
              docker-socket-proxy:
                image: tecnativa/docker-socket-proxy:0.3.0@sha256:{$d}
              write_db:
                image: postgres@sha256:{$d}
              backup-scheduler:
                image: \${VORTOS_IMAGE_BACKUP:?written by the deploy}
            YAML;
    }

    private function policy(): TopologyPolicy
    {
        return new TopologyPolicy('/opt/vortos', [new ImageProvenanceRule([
            'VORTOS_IMAGE_BACKUP' => new ComposeServiceSet(['backup-scheduler']),
        ])]);
    }

    /** @return list<string> */
    private function kinds(string $yaml): array
    {
        return array_map(static fn (TopologyViolation $v): string => $v->kind->value . ':' . $v->service, $this->policy()->evaluate($yaml));
    }

    public function test_digest_pins_and_declared_auxiliary_references_are_clear(): void
    {
        self::assertSame([], $this->kinds($this->clear()));
    }

    /** The finding: floating tags on the socket proxy, the database, and a sidecar on :main. */
    public function test_a_mutable_tag_is_refused(): void
    {
        $yaml = str_replace('image: postgres@sha256:' . self::D, 'image: postgres:18-alpine', $this->clear());
        $yaml = str_replace('image: ${VORTOS_IMAGE_BACKUP:?written by the deploy}', 'image: docker.io/realfortizan/sqoura-backup:main', $yaml);

        self::assertSame(['unpinned:write_db', 'unpinned:backup-scheduler', 'unconsumed:backup-scheduler'], $this->kinds($yaml));
    }

    public function test_a_malformed_digest_is_not_a_pin(): void
    {
        $yaml = str_replace('postgres@sha256:' . self::D, 'postgres@sha256:abc', $this->clear());

        self::assertSame(['unpinned:write_db'], $this->kinds($yaml));
    }

    public function test_building_on_the_host_or_naming_no_image_is_refused(): void
    {
        $yaml = $this->clear() . "\n  tools:\n    build: ./tools\n  bare:\n    restart: always\n";

        self::assertSame(['built_on_host:tools', 'missing_image:bare'], $this->kinds($yaml));
    }

    public function test_an_auxiliary_image_on_an_undeclared_service_is_refused(): void
    {
        $yaml = str_replace('image: postgres@sha256:' . self::D, 'image: ${VORTOS_IMAGE_BACKUP:?written by the deploy}', $this->clear());

        self::assertSame(['service_not_allowed:write_db'], $this->kinds($yaml));
    }

    public function test_any_other_interpolation_is_unverifiable_including_the_optional_form(): void
    {
        $yaml = str_replace('image: postgres@sha256:' . self::D, 'image: postgres:${PG_TAG}', $this->clear());
        $yaml = str_replace('${VORTOS_IMAGE_BACKUP:?written by the deploy}', '${VORTOS_IMAGE_BACKUP}', $yaml);

        self::assertSame(['unverifiable:write_db', 'unverifiable:backup-scheduler', 'unconsumed:backup-scheduler'], $this->kinds($yaml));
    }

    public function test_an_undeclared_auxiliary_variable_is_unverifiable(): void
    {
        $yaml = str_replace('image: postgres@sha256:' . self::D, 'image: ${VORTOS_IMAGE_POSTGRES:?x}', $this->clear());

        self::assertSame(['unverifiable:write_db'], $this->kinds($yaml));
    }
}
