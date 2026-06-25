<?php

declare(strict_types=1);

namespace Vortos\Iac\Driver\Terraform;

use Vortos\Iac\Exception\IacException;
use Vortos\Iac\Lifecycle\IacChangeAction;
use Vortos\Iac\Lifecycle\IacPlan;
use Vortos\Iac\Lifecycle\IacPlanSummary;
use Vortos\Iac\Lifecycle\IacResourceChange;

final class PlanJsonParser
{
    public function parse(string $json, string $planFile): IacPlan
    {
        if (trim($json) === '') {
            throw new IacException('Plan JSON is empty.');
        }

        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new IacException('Plan JSON root must be an object.');
        }

        $rawChanges = $data['resource_changes'] ?? [];
        if (!is_array($rawChanges)) {
            $rawChanges = [];
        }

        $add = 0;
        $change = 0;
        $destroy = 0;
        $replace = 0;
        $resourceChanges = [];

        foreach ($rawChanges as $rc) {
            if (!is_array($rc)) {
                continue;
            }

            $address = (string) ($rc['address'] ?? '');
            $type = (string) ($rc['type'] ?? '');
            $provider = (string) ($rc['provider_name'] ?? '');

            $actions = $rc['change']['actions'] ?? [];
            if (!is_array($actions)) {
                $actions = [];
            }

            $action = $this->resolveAction($actions);

            match ($action) {
                IacChangeAction::Create => $add++,
                IacChangeAction::Update => $change++,
                IacChangeAction::Delete => $destroy++,
                IacChangeAction::Replace => $replace++,
                default => null,
            };

            if ($address !== '') {
                $resourceChanges[] = new IacResourceChange($address, $type, $action, $provider);
            }
        }

        $summary = new IacPlanSummary($add, $change, $destroy, $replace);
        $digest = hash('sha256', $json);
        $fileDigest = hash_file('sha256', $planFile);

        if ($fileDigest === false) {
            throw new IacException(sprintf("Cannot read plan file '%s' for integrity hashing.", $planFile));
        }

        return new IacPlan($summary, $resourceChanges, $planFile, $digest, $fileDigest);
    }

    /** @param list<string> $actions */
    private function resolveAction(array $actions): IacChangeAction
    {
        if ($actions === ['no-op'] || $actions === []) {
            return IacChangeAction::NoOp;
        }

        if ($actions === ['create']) {
            return IacChangeAction::Create;
        }

        if ($actions === ['update']) {
            return IacChangeAction::Update;
        }

        if ($actions === ['delete']) {
            return IacChangeAction::Delete;
        }

        if ($actions === ['read']) {
            return IacChangeAction::Read;
        }

        if (in_array('delete', $actions, true) && in_array('create', $actions, true)) {
            return IacChangeAction::Replace;
        }

        if (in_array('create', $actions, true) && in_array('delete', $actions, true)) {
            return IacChangeAction::Replace;
        }

        return IacChangeAction::Update;
    }
}
