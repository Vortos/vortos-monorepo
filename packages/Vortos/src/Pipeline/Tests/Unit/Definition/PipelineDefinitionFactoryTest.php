<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Definition;

use PHPUnit\Framework\TestCase;
use Vortos\Foundation\Deploy\DeployPosture;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Definition\PipelineDefinitionBuilder;
use Vortos\Pipeline\Definition\PipelineDefinitionFactory;

final class PipelineDefinitionFactoryTest extends TestCase
{
    private string $dir;

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    private const ENV_KEYS = [
        'PIPELINE_EMITTER',
        'PIPELINE_PHP_VERSION',
        'PIPELINE_ENVIRONMENTS',
        'PIPELINE_BENCHMARK',
        'PIPELINE_EMIT_SBOM',
        'PIPELINE_OIDC',
        'PIPELINE_DEPLOY_POSTURE',
        'PIPELINE_DEFAULT_TIMEOUT_MINUTES',
        'PIPELINE_IMAGE_REPOSITORY',
        'PIPELINE_NATIVE_RUNNER_LABEL',
    ];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pipeline-factory-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/config', 0o777, true);

        foreach (self::ENV_KEYS as $k) {
            $this->savedEnv[$k] = $_ENV[$k] ?? false;
            unset($_ENV[$k]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $k => $v) {
            if ($v === false) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }

        @unlink($this->dir . '/config/pipeline.php');
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    private function writeConfig(string $body): void
    {
        file_put_contents($this->dir . '/config/pipeline.php', "<?php\n" . $body);
    }

    public function test_no_config_no_env_yields_defaults(): void
    {
        $def = (new PipelineDefinitionFactory())($this->dir);

        self::assertEquals(new PipelineDefinition(), $def);
    }

    public function test_config_file_returning_a_builder_is_used_verbatim(): void
    {
        $this->writeConfig(<<<'PHP'
            use Vortos\Pipeline\Definition\PipelineDefinitionBuilder;
            return PipelineDefinitionBuilder::create()
                ->phpVersion('8.4')
                ->registryProvider('dockerhub');
            PHP);

        $def = (new PipelineDefinitionFactory())($this->dir);

        self::assertSame('8.4', $def->phpVersion);
        self::assertSame('dockerhub', $def->registryProvider);
    }

    public function test_config_file_returning_a_definition_is_used_verbatim(): void
    {
        $this->writeConfig(<<<'PHP'
            use Vortos\Pipeline\Definition\PipelineDefinition;
            return new PipelineDefinition(phpVersion: '8.3');
            PHP);

        // Env must NOT overlay an explicit definition.
        $_ENV['PIPELINE_PHP_VERSION'] = '9.9';

        $def = (new PipelineDefinitionFactory())($this->dir);

        self::assertSame('8.3', $def->phpVersion);
    }

    public function test_array_config_wires_the_three_previously_dropped_params(): void
    {
        $this->writeConfig(<<<'PHP'
            return [
                'default_timeout_minutes' => 45,
                'oidc' => true,
                'split_packages' => [
                    ['local_path' => 'packages/Foo', 'split_repository' => 'org/foo'],
                ],
            ];
            PHP);

        $def = (new PipelineDefinitionFactory())($this->dir);

        self::assertSame(45, $def->defaultTimeoutMinutes);
        self::assertTrue($def->oidc);
        self::assertCount(1, $def->splitPackageOverrides);
        self::assertSame('packages/Foo', $def->splitPackageOverrides[0]->localPath);
    }

    public function test_env_overlays_array_config_gaps_file_wins(): void
    {
        $this->writeConfig(<<<'PHP'
            return ['php_version' => '8.4'];
            PHP);

        $_ENV['PIPELINE_PHP_VERSION'] = '9.9';       // loses to file
        $_ENV['PIPELINE_EMITTER']     = 'gitlab';    // fills a gap

        $def = (new PipelineDefinitionFactory())($this->dir);

        self::assertSame('8.4', $def->phpVersion);
        self::assertSame('gitlab', $def->emitter);
    }

    public function test_default_timeout_from_env(): void
    {
        $_ENV['PIPELINE_DEFAULT_TIMEOUT_MINUTES'] = '60';

        $def = (new PipelineDefinitionFactory())($this->dir);

        self::assertSame(60, $def->defaultTimeoutMinutes);
    }

    public function test_oidc_defaults_false_without_posture_even_with_image_repository(): void
    {
        $_ENV['PIPELINE_IMAGE_REPOSITORY']    = 'ghcr.io/org/app';
        $_ENV['PIPELINE_NATIVE_RUNNER_LABEL'] = 'ubuntu-24.04-arm';

        $def = (new PipelineDefinitionFactory())($this->dir);

        // GAP-H: no posture ⇒ OIDC stays off regardless of imageRepository (was: derived true).
        self::assertFalse($def->oidc);
    }

    public function test_oidc_derives_from_posture_env(): void
    {
        $_ENV['PIPELINE_IMAGE_REPOSITORY']    = 'ghcr.io/org/app';
        $_ENV['PIPELINE_NATIVE_RUNNER_LABEL'] = 'ubuntu-24.04-arm';
        $_ENV['PIPELINE_DEPLOY_POSTURE']      = 'ssh-ca-oidc';

        $def = (new PipelineDefinitionFactory())($this->dir);

        self::assertSame(DeployPosture::SshCaOidc, $def->posture);
        self::assertTrue($def->oidc);
    }

    public function test_ssh_key_posture_keeps_oidc_off(): void
    {
        $_ENV['PIPELINE_IMAGE_REPOSITORY']    = 'ghcr.io/org/app';
        $_ENV['PIPELINE_NATIVE_RUNNER_LABEL'] = 'ubuntu-24.04-arm';
        $_ENV['PIPELINE_DEPLOY_POSTURE']      = 'ssh-key';

        $def = (new PipelineDefinitionFactory())($this->dir);

        self::assertSame(DeployPosture::SshKey, $def->posture);
        self::assertFalse($def->oidc);
    }

    public function test_explicit_oidc_env_overrides_posture(): void
    {
        $_ENV['PIPELINE_IMAGE_REPOSITORY']    = 'ghcr.io/org/app';
        $_ENV['PIPELINE_NATIVE_RUNNER_LABEL'] = 'ubuntu-24.04-arm';
        $_ENV['PIPELINE_DEPLOY_POSTURE']      = 'ssh-key';
        $_ENV['PIPELINE_OIDC']                = 'true';

        $def = (new PipelineDefinitionFactory())($this->dir);

        self::assertTrue($def->oidc);
    }
}
