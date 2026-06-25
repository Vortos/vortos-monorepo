<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Iam;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Iac\Definition\AbstractExporterDefinition;

final class IamExporterDefinition extends AbstractExporterDefinition
{
    private ?IamProvider $provider = null;
    private ?string $roleName = null;
    private ?string $assumeRolePolicy = null;
    /** @var list<string> */
    private array $policyArns = [];
    private ?string $serviceAccountId = null;
    private ?string $project = null;
    /** @var list<array{role: string, member: string}> */
    private array $bindings = [];

    public function provider(IamProvider $provider): static { $this->provider = $provider; return $this; }
    public function roleName(string $name): static { $this->roleName = $name; return $this; }
    public function assumeRolePolicy(string $policy): static { $this->assumeRolePolicy = $policy; return $this; }
    public function policyArn(string $arn): static { $this->policyArns[] = $arn; return $this; }
    public function serviceAccountId(string $id): static { $this->serviceAccountId = $id; return $this; }
    public function project(string $project): static { $this->project = $project; return $this; }
    public function binding(string $role, string $member): static { $this->bindings[] = ['role' => $role, 'member' => $member]; return $this; }

    public function exporterClass(): string { return IamExporter::class; }

    public function compileSpec(ContainerBuilder $container): array
    {
        if ($this->provider === null) {
            throw new \LogicException(sprintf("IAM exporter '%s' declares no provider().", $this->name));
        }
        $spec = ['provider' => $this->provider->value, 'label' => str_replace('-', '_', $this->name)];
        if ($this->roleName !== null) { $spec['role_name'] = $this->roleName; }
        if ($this->assumeRolePolicy !== null) { $spec['assume_role_policy'] = $this->assumeRolePolicy; }
        if ($this->policyArns !== []) { $spec['policy_arns'] = $this->policyArns; }
        if ($this->serviceAccountId !== null) { $spec['service_account_id'] = $this->serviceAccountId; }
        if ($this->project !== null) { $spec['project'] = $this->project; }
        if ($this->bindings !== []) { $spec['bindings'] = $this->bindings; }
        return $spec;
    }
}
