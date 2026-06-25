<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Iam;

use Vortos\Iac\Export\ExporterInterface;
use Vortos\Iac\Terraform\TerraformDocument;

final class IamExporter implements ExporterInterface
{
    public function export(array $entry): TerraformDocument
    {
        $spec = $entry['spec'];
        $provider = IamProvider::from($spec['provider']);
        $document = new TerraformDocument($entry['allowed_literals'] ?? []);

        match ($provider) {
            IamProvider::Aws => $this->exportAws($spec, $document),
            IamProvider::Gcp => $this->exportGcp($spec, $document),
        };

        return $document;
    }

    /** @param array<string, mixed> $entry */
    public function countResources(array $entry): int
    {
        $spec = $entry['spec'];
        $count = 1;
        $count += count($spec['policy_arns'] ?? []);
        $count += count($spec['bindings'] ?? []);
        return $count;
    }

    /** @param array<string, mixed> $spec */
    private function exportAws(array $spec, TerraformDocument $document): void
    {
        $document->requiredProvider('aws', 'hashicorp/aws', '~> 5.0');
        $label = $spec['label'];

        $roleAttrs = ['name' => $spec['role_name'] ?? $label];
        if (isset($spec['assume_role_policy'])) {
            $roleAttrs['assume_role_policy'] = $spec['assume_role_policy'];
        }
        $document->resource('aws_iam_role', $label, $roleAttrs);

        foreach ($spec['policy_arns'] ?? [] as $i => $arn) {
            $document->resource('aws_iam_role_policy_attachment', $label . '_p' . $i, [
                'role' => '${aws_iam_role.' . $label . '.name}',
                'policy_arn' => $arn,
            ]);
        }
    }

    /** @param array<string, mixed> $spec */
    private function exportGcp(array $spec, TerraformDocument $document): void
    {
        $document->requiredProvider('google', 'hashicorp/google', '~> 5.0');
        $label = $spec['label'];

        $saAttrs = ['account_id' => $spec['service_account_id'] ?? $label, 'display_name' => $label];
        if (isset($spec['project'])) {
            $saAttrs['project'] = $spec['project'];
        }
        $document->resource('google_service_account', $label, $saAttrs);

        foreach ($spec['bindings'] ?? [] as $i => $binding) {
            $document->resource('google_project_iam_member', $label . '_b' . $i, [
                'project' => $spec['project'] ?? '${data.google_project.project_id}',
                'role' => $binding['role'],
                'member' => $binding['member'],
            ]);
        }
    }
}
