<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Execution\CommandResult;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;
use Vortos\DeployK8s\Api\KubeApiConflictException;
use Vortos\DeployK8s\Api\KubeApiException;
use Vortos\DeployK8s\Api\KubeObject;
use Vortos\DeployK8s\Api\KubectlKubeApi;

final class KubectlKubeApiTest extends TestCase
{
    public function test_apply_uses_server_side_flag(): void
    {
        $runner = new FakeCommandRunner();
        $runner->addResult(new CommandResult(0, '', '', 0.1));

        $api = new KubectlKubeApi($runner);
        $obj = new KubeObject('Deployment', 'test', 'default', [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => ['name' => 'test', 'namespace' => 'default'],
        ]);

        $api->apply($obj);

        $this->assertCount(1, $runner->calls);
        $argv = $runner->calls[0]['argv'];
        $this->assertSame('kubectl', $argv[0]);
        $this->assertContains('apply', $argv);
        $this->assertContains('--server-side', $argv);
        $this->assertContains('-f', $argv);
        $this->assertContains('-', $argv);
    }

    public function test_apply_sends_json_via_stdin(): void
    {
        $runner = new FakeCommandRunner();
        $runner->addResult(new CommandResult(0, '', '', 0.1));

        $api = new KubectlKubeApi($runner);
        $obj = new KubeObject('Service', 'svc', 'ns', [
            'apiVersion' => 'v1',
            'kind' => 'Service',
        ]);

        $api->apply($obj);

        $this->assertNotNull($runner->calls[0]['stdin']);
        $this->assertJson($runner->calls[0]['stdin']);
    }

    public function test_apply_throws_on_failure(): void
    {
        $runner = new FakeCommandRunner();
        $runner->addResult(new CommandResult(1, '', 'error', 0.1));

        $api = new KubectlKubeApi($runner);
        $obj = new KubeObject('Deployment', 'test', 'default', []);

        $this->expectException(KubeApiException::class);
        $api->apply($obj);
    }

    public function test_no_insecure_skip_tls_verify(): void
    {
        $runner = new FakeCommandRunner();
        $runner->addResult(new CommandResult(0, '', '', 0.1));

        $api = new KubectlKubeApi($runner);
        $obj = new KubeObject('Deployment', 'test', 'default', []);

        $api->apply($obj);

        $argv = $runner->calls[0]['argv'];
        $this->assertNotContains('--insecure-skip-tls-verify', $argv);
    }

    public function test_argv_is_array_no_shell_strings(): void
    {
        $runner = new FakeCommandRunner();
        $runner->addResult(new CommandResult(0, '', '', 0.1));

        $api = new KubectlKubeApi($runner);
        $obj = new KubeObject('Deployment', 'test', 'default', []);

        $api->apply($obj);

        $argv = $runner->calls[0]['argv'];
        foreach ($argv as $arg) {
            $this->assertIsString($arg);
            $this->assertStringNotContainsString('|', $arg, 'No pipe in argv');
            $this->assertStringNotContainsString(';', $arg, 'No semicolon in argv');
            $this->assertStringNotContainsString('`', $arg, 'No backtick in argv');
            $this->assertStringNotContainsString('$(', $arg, 'No command substitution in argv');
        }
    }

    public function test_kubeconfig_flag_added_when_set(): void
    {
        $runner = new FakeCommandRunner();
        $runner->addResult(new CommandResult(0, '', '', 0.1));

        $api = new KubectlKubeApi($runner, kubeconfig: '/path/to/kubeconfig');
        $obj = new KubeObject('Deployment', 'test', 'default', []);

        $api->apply($obj);

        $argv = $runner->calls[0]['argv'];
        $idx = array_search('--kubeconfig', $argv, true);
        $this->assertNotFalse($idx);
        $this->assertSame('/path/to/kubeconfig', $argv[$idx + 1]);
    }

    public function test_context_flag_added_when_set(): void
    {
        $runner = new FakeCommandRunner();
        $runner->addResult(new CommandResult(0, '', '', 0.1));

        $api = new KubectlKubeApi($runner, context: 'my-cluster');
        $obj = new KubeObject('Deployment', 'test', 'default', []);

        $api->apply($obj);

        $argv = $runner->calls[0]['argv'];
        $this->assertContains('--context', $argv);
        $this->assertContains('my-cluster', $argv);
    }

    public function test_scale_builds_correct_argv(): void
    {
        $runner = new FakeCommandRunner();
        $runner->addResult(new CommandResult(0, '', '', 0.1));

        $api = new KubectlKubeApi($runner);
        $api->scale('Deployment', 'worker-queue', 'prod', 5);

        $argv = $runner->calls[0]['argv'];
        $this->assertContains('scale', $argv);
        $this->assertContains('deployment/worker-queue', $argv);
        $this->assertContains('--namespace', $argv);
        $this->assertContains('prod', $argv);
        $this->assertContains('--replicas', $argv);
        $this->assertContains('5', $argv);
    }

    public function test_delete_uses_ignore_not_found(): void
    {
        $runner = new FakeCommandRunner();
        $runner->addResult(new CommandResult(0, '', '', 0.1));

        $api = new KubectlKubeApi($runner);
        $api->delete('Job', 'migrate', 'default');

        $argv = $runner->calls[0]['argv'];
        $this->assertContains('delete', $argv);
        $this->assertContains('--ignore-not-found', $argv);
    }

    public function test_patch_service_selector_detects_conflict(): void
    {
        $runner = new FakeCommandRunner();
        // getService call
        $runner->addResult(new CommandResult(0, json_encode([
            'metadata' => ['resourceVersion' => '99'],
            'spec' => ['selector' => ['app' => 'old'], 'ports' => [['port' => 80]]],
        ]), '', 0.1));

        $api = new KubectlKubeApi($runner);

        $this->expectException(KubeApiConflictException::class);
        $api->patchServiceSelector('svc', 'default', ['app' => 'new'], '50');
    }

    public function test_get_service_returns_null_on_not_found(): void
    {
        $runner = new FakeCommandRunner();
        $runner->addResult(new CommandResult(1, '', 'not found', 0.1));

        $api = new KubectlKubeApi($runner);
        $this->assertNull($api->getService('missing', 'default'));
    }

    public function test_get_service_parses_json_response(): void
    {
        $runner = new FakeCommandRunner();
        $runner->addResult(new CommandResult(0, json_encode([
            'metadata' => ['resourceVersion' => '42'],
            'spec' => [
                'selector' => ['app.kubernetes.io/color' => 'blue'],
                'ports' => [['port' => 8080]],
            ],
        ]), '', 0.1));

        $api = new KubectlKubeApi($runner);
        $svc = $api->getService('app', 'default');

        $this->assertNotNull($svc);
        $this->assertSame('42', $svc->resourceVersion);
        $this->assertSame('blue', $svc->selector['app.kubernetes.io/color']);
        $this->assertSame(8080, $svc->port);
    }
}
