<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Driver\Terraform;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Driver\Terraform\PlanJsonParser;
use Vortos\Iac\Exception\IacException;
use Vortos\Iac\Lifecycle\IacChangeAction;

final class PlanJsonParserTest extends TestCase
{
    private PlanJsonParser $parser;
    private string $planFile;

    protected function setUp(): void
    {
        $this->parser = new PlanJsonParser();
        $this->planFile = sys_get_temp_dir() . '/test-plan-' . bin2hex(random_bytes(4)) . '.bin';
        file_put_contents($this->planFile, 'plan-binary-data');
    }

    protected function tearDown(): void
    {
        @unlink($this->planFile);
    }

    public function test_parses_golden_fixture_with_mixed_actions(): void
    {
        $json = file_get_contents(__DIR__ . '/../../Fixtures/plan_create.json');
        $plan = $this->parser->parse($json, $this->planFile);

        $this->assertSame(1, $plan->summary->add);
        $this->assertSame(1, $plan->summary->change);
        $this->assertSame(1, $plan->summary->destroy);
        $this->assertSame(1, $plan->summary->replace);
        $this->assertTrue($plan->hasChanges());
        $this->assertTrue($plan->isDestructive());
        $this->assertSame(2, $plan->destructiveCount());
        $this->assertSame($this->planFile, $plan->planFile);
        $this->assertSame(hash('sha256', $json), $plan->rawJsonDigest);
        $this->assertSame(hash_file('sha256', $this->planFile), $plan->planFileDigest);
    }

    public function test_resource_changes_are_parsed_correctly(): void
    {
        $json = file_get_contents(__DIR__ . '/../../Fixtures/plan_create.json');
        $plan = $this->parser->parse($json, $this->planFile);

        $byAddress = [];
        foreach ($plan->resourceChanges as $rc) {
            $byAddress[$rc->address] = $rc;
        }

        $this->assertSame(IacChangeAction::Create, $byAddress['null_resource.created']->action);
        $this->assertSame(IacChangeAction::Update, $byAddress['null_resource.updated']->action);
        $this->assertSame(IacChangeAction::Delete, $byAddress['null_resource.deleted']->action);
        $this->assertSame(IacChangeAction::Replace, $byAddress['null_resource.replaced']->action);
        $this->assertSame(IacChangeAction::NoOp, $byAddress['null_resource.unchanged']->action);
        $this->assertSame(IacChangeAction::Read, $byAddress['null_resource.data_read']->action);
    }

    public function test_resource_change_type_and_provider_are_parsed(): void
    {
        $json = file_get_contents(__DIR__ . '/../../Fixtures/plan_create.json');
        $plan = $this->parser->parse($json, $this->planFile);

        foreach ($plan->resourceChanges as $rc) {
            $this->assertSame('null_resource', $rc->type);
            $this->assertSame('registry.terraform.io/hashicorp/null', $rc->provider);
        }
    }

    public function test_create_only_plan(): void
    {
        $json = json_encode([
            'resource_changes' => [
                ['address' => 'res.a', 'type' => 't', 'provider_name' => 'p', 'change' => ['actions' => ['create']]],
                ['address' => 'res.b', 'type' => 't', 'provider_name' => 'p', 'change' => ['actions' => ['create']]],
            ],
        ]);

        $plan = $this->parser->parse($json, $this->planFile);
        $this->assertSame(2, $plan->summary->add);
        $this->assertSame(0, $plan->summary->destroy);
        $this->assertFalse($plan->isDestructive());
    }

    public function test_no_op_plan_has_no_changes(): void
    {
        $json = json_encode([
            'resource_changes' => [
                ['address' => 'res.a', 'type' => 't', 'provider_name' => 'p', 'change' => ['actions' => ['no-op']]],
            ],
        ]);

        $plan = $this->parser->parse($json, $this->planFile);
        $this->assertFalse($plan->hasChanges());
        $this->assertSame(0, $plan->summary->total());
    }

    public function test_empty_resource_changes(): void
    {
        $json = json_encode(['resource_changes' => []]);
        $plan = $this->parser->parse($json, $this->planFile);
        $this->assertFalse($plan->hasChanges());
        $this->assertSame([], $plan->resourceChanges);
    }

    public function test_missing_resource_changes_key(): void
    {
        $json = json_encode(['terraform_version' => '1.7.0']);
        $plan = $this->parser->parse($json, $this->planFile);
        $this->assertFalse($plan->hasChanges());
    }

    public function test_empty_json_throws(): void
    {
        $this->expectException(IacException::class);
        $this->expectExceptionMessage('Plan JSON is empty');
        $this->parser->parse('', '/tmp/p.bin');
    }

    public function test_whitespace_only_json_throws(): void
    {
        $this->expectException(IacException::class);
        $this->expectExceptionMessage('Plan JSON is empty');
        $this->parser->parse('   ', '/tmp/p.bin');
    }

    public function test_malformed_json_throws(): void
    {
        $this->expectException(\JsonException::class);
        $this->parser->parse('{not valid json', '/tmp/p.bin');
    }

    public function test_json_string_root_throws(): void
    {
        $this->expectException(IacException::class);
        $this->expectExceptionMessage('Plan JSON root must be an object');
        $this->parser->parse('"just a string"', '/tmp/p.bin');
    }

    public function test_replace_with_create_delete_order(): void
    {
        $json = json_encode([
            'resource_changes' => [
                ['address' => 'r.x', 'type' => 't', 'provider_name' => 'p', 'change' => ['actions' => ['create', 'delete']]],
            ],
        ]);

        $plan = $this->parser->parse($json, $this->planFile);
        $this->assertSame(1, $plan->summary->replace);
        $this->assertSame(IacChangeAction::Replace, $plan->resourceChanges[0]->action);
    }

    public function test_read_action_is_parsed(): void
    {
        $json = json_encode([
            'resource_changes' => [
                ['address' => 'data.r.x', 'type' => 't', 'provider_name' => 'p', 'change' => ['actions' => ['read']]],
            ],
        ]);

        $plan = $this->parser->parse($json, $this->planFile);
        $this->assertFalse($plan->hasChanges());
        $this->assertSame(IacChangeAction::Read, $plan->resourceChanges[0]->action);
    }

    public function test_digest_is_sha256_of_raw_json(): void
    {
        $json = json_encode(['resource_changes' => []]);
        $plan = $this->parser->parse($json, $this->planFile);
        $this->assertSame(hash('sha256', $json), $plan->rawJsonDigest);
        $this->assertSame(hash_file('sha256', $this->planFile), $plan->planFileDigest);
    }

    public function test_non_array_resource_changes_skipped(): void
    {
        $json = json_encode([
            'resource_changes' => ['not-an-array', 42, null],
        ]);

        $plan = $this->parser->parse($json, $this->planFile);
        $this->assertFalse($plan->hasChanges());
        $this->assertSame([], $plan->resourceChanges);
    }

    public function test_resource_change_with_empty_address_is_skipped(): void
    {
        $json = json_encode([
            'resource_changes' => [
                ['address' => '', 'type' => 't', 'provider_name' => 'p', 'change' => ['actions' => ['create']]],
            ],
        ]);

        $plan = $this->parser->parse($json, $this->planFile);
        $this->assertSame(1, $plan->summary->add);
        $this->assertSame([], $plan->resourceChanges);
    }

    public function test_unknown_action_combination_defaults_to_update(): void
    {
        $json = json_encode([
            'resource_changes' => [
                ['address' => 'r.x', 'type' => 't', 'provider_name' => 'p', 'change' => ['actions' => ['some-future-action']]],
            ],
        ]);

        $plan = $this->parser->parse($json, $this->planFile);
        $this->assertSame(1, $plan->summary->change);
        $this->assertSame(IacChangeAction::Update, $plan->resourceChanges[0]->action);
    }
}
