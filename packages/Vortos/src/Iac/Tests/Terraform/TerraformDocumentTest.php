<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Terraform;

use PHPUnit\Framework\TestCase;
use Vortos\Foundation\Config\Env;
use Vortos\Iac\Exception\IacException;
use Vortos\Iac\Exception\SecretLiteralException;
use Vortos\Iac\Terraform\TerraformDocument;
use Vortos\Iac\Terraform\TfLiteral;
use Vortos\Iac\Terraform\TfReference;
use Vortos\Iac\Terraform\TfVariable;

final class TerraformDocumentTest extends TestCase
{
    public function test_rendering_is_deterministic_and_sorted(): void
    {
        $build = static function (): string {
            $doc = new TerraformDocument();
            $doc->requiredProvider('kafka', 'Mongey/kafka', '~> 0.7');
            $doc->resource('kafka_topic', 'zeta', ['name' => 'zeta', 'partitions' => 3]);
            $doc->resource('kafka_topic', 'alpha', ['partitions' => 1, 'name' => 'alpha']);
            return $doc->render();
        };

        $first = $build();

        $this->assertSame($first, $build(), 'Same input must render byte-identically');
        $this->assertStringEndsWith("\n", $first);

        $decoded = json_decode($first, true);
        $this->assertSame(['alpha', 'zeta'], array_keys($decoded['resource']['kafka_topic']));
        $this->assertSame(['name', 'partitions'], array_keys($decoded['resource']['kafka_topic']['alpha']));
        $this->assertStringContainsString(TerraformDocument::GENERATED_MARKER, $first);
    }

    public function test_literal_strings_cannot_smuggle_expressions(): void
    {
        $doc = new TerraformDocument();
        $doc->resource('kafka_topic', 'evil', [
            'name' => '${file("/etc/passwd")}',
            'config' => ['comment' => '%{ for x in [1] }boom%{ endfor }'],
        ]);

        $decoded = json_decode($doc->render(), true);

        $this->assertSame('$${file("/etc/passwd")}', $decoded['resource']['kafka_topic']['evil']['name']);
        $this->assertSame(
            '%%{ for x in [1] }boom%%{ endfor }',
            $decoded['resource']['kafka_topic']['evil']['config']['comment'],
        );
    }

    public function test_variable_and_reference_render_expressions(): void
    {
        $doc = new TerraformDocument();
        $partitions = TfVariable::number('kafka_partitions', 12, 'From environment variable KAFKA_PARTITIONS.');
        $doc->variable($partitions);
        $doc->resource('kafka_topic', 'orders', [
            'partitions' => $partitions,
            'cluster' => new TfReference('confluent_kafka_cluster.main.id'),
        ]);

        $decoded = json_decode($doc->render(), true);

        $this->assertSame('${var.kafka_partitions}', $decoded['resource']['kafka_topic']['orders']['partitions']);
        $this->assertSame('${confluent_kafka_cluster.main.id}', $decoded['resource']['kafka_topic']['orders']['cluster']);
        $this->assertSame(['default' => 12, 'description' => 'From environment variable KAFKA_PARTITIONS.', 'type' => 'number'], $decoded['variable']['kafka_partitions']);
    }

    public function test_secret_named_attribute_with_literal_value_is_rejected(): void
    {
        $doc = new TerraformDocument();

        $this->expectException(SecretLiteralException::class);
        $this->expectExceptionMessage("Attribute 'config.sasl_password'");

        $doc->resource('kafka_topic', 'orders', ['config' => ['sasl_password' => 'hunter2']]);
    }

    public function test_allow_literal_path_opts_out_of_secret_gate(): void
    {
        $doc = new TerraformDocument(allowedLiteralPaths: ['config.token_audience']);
        $doc->resource('kafka_topic', 'orders', ['config' => ['token_audience' => 'public-api']]);

        $this->assertStringContainsString('public-api', $doc->render());
    }

    public function test_secret_variable_is_fine_where_literal_is_not(): void
    {
        $doc = new TerraformDocument();
        $secret = TfVariable::string('sasl_password', sensitive: true);
        $doc->variable($secret);
        $doc->resource('kafka_topic', 'orders', ['config' => ['sasl_password' => $secret]]);

        $decoded = json_decode($doc->render(), true);
        $this->assertTrue($decoded['variable']['sasl_password']['sensitive']);
    }

    public function test_raw_env_reference_is_rejected(): void
    {
        $doc = new TerraformDocument();

        $this->expectException(IacException::class);
        $this->expectExceptionMessage('Raw Env reference');

        $doc->resource('kafka_topic', 'orders', ['partitions' => Env::int('KAFKA_PARTITIONS')]);
    }

    public function test_duplicate_resource_label_is_rejected(): void
    {
        $doc = new TerraformDocument();
        $doc->resource('kafka_topic', 'orders', ['name' => 'a']);

        $this->expectException(IacException::class);
        $doc->resource('kafka_topic', 'orders', ['name' => 'b']);
    }

    public function test_invalid_identifiers_are_rejected(): void
    {
        $doc = new TerraformDocument();

        $this->expectException(IacException::class);
        $doc->resource('kafka_topic', 'bad label!', []);
    }

    public function test_conflicting_variable_specs_are_rejected(): void
    {
        $doc = new TerraformDocument();
        $doc->variable(TfVariable::number('kafka_partitions', 12));
        $doc->variable(TfVariable::number('kafka_partitions', 12)); // identical → fine

        $this->expectException(IacException::class);
        $doc->variable(TfVariable::number('kafka_partitions', 24));
    }

    public function test_variables_document_holds_only_variables(): void
    {
        $doc = new TerraformDocument();
        $doc->variable(TfVariable::string('kafka_brokers'));
        $doc->resource('kafka_topic', 'orders', ['name' => 'orders']);

        $varsDoc = json_decode($doc->variablesDocument()->render(), true);
        $main = json_decode($doc->render(includeVariables: false), true);

        $this->assertArrayHasKey('variable', $varsDoc);
        $this->assertArrayNotHasKey('resource', $varsDoc);
        $this->assertArrayNotHasKey('variable', $main);
    }

    public function test_invalid_variable_and_reference_names_are_rejected(): void
    {
        $this->expectException(IacException::class);
        TfVariable::string('Bad-Name');
    }

    public function test_reference_segments_are_validated(): void
    {
        $this->expectException(IacException::class);
        new TfReference('a.${injection}');
    }

    public function test_tf_literal_rejects_objects(): void
    {
        $this->expectException(IacException::class);
        new TfLiteral(new \stdClass());
    }
}
