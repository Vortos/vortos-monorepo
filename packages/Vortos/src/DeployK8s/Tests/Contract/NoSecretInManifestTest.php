<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Vortos\DeployK8s\Manifest\KubernetesManifestRenderer;
use Vortos\DeployK8s\Manifest\PodSecurityProfile;
use Vortos\DeployK8s\Manifest\RbacRenderer;
use Vortos\DeployK8s\Manifest\RenderContext;
use Vortos\Docker\Worker\WorkerProcessRegistry;

final class NoSecretInManifestTest extends TestCase
{
    public function test_secrets_referenced_via_secret_ref_not_inlined(): void
    {
        $renderer = new KubernetesManifestRenderer(new PodSecurityProfile(), new RbacRenderer());

        $ctx = new RenderContext(
            imageReference: 'app@sha256:' . str_repeat('a', 64),
            secretRefs: ['app-secrets'],
            envVars: ['DB_PASSWORD' => 'secretKeyRef:app-secrets:db-password'],
        );

        $workers = new WorkerProcessRegistry([]);
        $objects = $renderer->render($workers, $ctx);

        foreach ($objects as $obj) {
            if ($obj->kind !== 'Deployment') {
                continue;
            }

            $containers = $obj->spec['spec']['template']['spec']['containers'] ?? [];
            foreach ($containers as $container) {
                if (isset($container['envFrom'])) {
                    foreach ($container['envFrom'] as $envFrom) {
                        $this->assertArrayHasKey('secretRef', $envFrom, 'Secrets must use secretRef, not inline values.');
                    }
                }

                if (isset($container['env'])) {
                    foreach ($container['env'] as $envVar) {
                        if (isset($envVar['valueFrom'])) {
                            $this->assertArrayHasKey(
                                'secretKeyRef',
                                $envVar['valueFrom'],
                                sprintf('Env var "%s" must use secretKeyRef.', $envVar['name']),
                            );
                        }
                    }
                }
            }
        }
    }

    public function test_no_plaintext_secret_patterns_in_manifest_json(): void
    {
        $renderer = new KubernetesManifestRenderer(new PodSecurityProfile(), new RbacRenderer());

        $ctx = new RenderContext(
            imageReference: 'app@sha256:' . str_repeat('a', 64),
            secretRefs: ['app-secrets'],
        );

        $workers = new WorkerProcessRegistry([]);
        $objects = $renderer->render($workers, $ctx);

        foreach ($objects as $obj) {
            $json = $obj->toJson();
            $this->assertStringNotContainsString('"password":', strtolower($json));
            $this->assertStringNotContainsString('"secret_key":', strtolower($json));
            $this->assertStringNotContainsString('"api_key":', strtolower($json));
        }
    }
}
