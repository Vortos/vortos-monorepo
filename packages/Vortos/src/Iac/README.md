# Vortos IaC — Terraform Export

Generates Terraform (`.tf.json`) files from resource declarations the framework
already compiles — Kafka transports from `MessagingConfig`, the object-store
bucket from `vortos-object-store`. Pure codegen: **no cloud credentials, no
network calls, no infrastructure mutation**. Terraform stays the provisioning
engine; Vortos config becomes the source of intent.

## Quick start

```bash
php bin/console vortos:make:infra-config --provider=confluent
```

This scaffolds `src/Shared/Infrastructure/Iac/AppInfraConfig.php`:

```php
#[InfraConfig]
final class AppInfraConfig
{
    #[RegisterTerraformExporter]
    public function kafkaTopics(): KafkaTopicsExporterDefinition
    {
        return KafkaTopicsExporterDefinition::create('kafka-topics')
            ->provider(KafkaProvider::Confluent)
            ->clusterRef('confluent_kafka_cluster.main')
            ->outputFile('infra/kafka_topics.tf.json');
    }
}
```

Register it in your DI config (like any `MessagingConfig`), then:

```bash
php bin/console vortos:iac:export            # write infra/kafka_topics.tf.json (+ _variables)
php bin/console vortos:iac:export --dry-run  # print to stdout
php bin/console vortos:iac:export --check    # CI: exit 1 if files drifted
```

Resource *shape* (partitions, retention, replication) stays in each module's
`MessagingConfig` — `partitions()`, `replicationFactor()`, `topicConfig()` are
the provisioning intent. `InfraConfig` is app-level (one per project, a
deployment concern), choosing only providers and output paths.

## Providers

| Resource | Provider enum | Terraform resource |
|---|---|---|
| Kafka topic | `KafkaProvider::Confluent` | `confluent_kafka_topic` (confluentinc/confluent) |
| Kafka topic | `KafkaProvider::Kafka` | `kafka_topic` (Mongey/kafka — self-hosted & MSK) |
| Bucket | `ObjectStoreProvider::Aws` | `aws_s3_bucket` (hashicorp/aws) |
| Bucket | `ObjectStoreProvider::CloudflareR2` | `cloudflare_r2_bucket` (cloudflare/cloudflare) |

## Security model

- **Secrets cannot reach generated files.** `Env` references become typed
  Terraform `variable` blocks (`sensitive = true` when the name looks secret);
  values come from `terraform.tfvars`/CI at apply time. Literal values on
  secret-looking attributes fail the export (`->allowLiteral('path')` is the
  explicit, greppable opt-out). SASL/SSL/DSN settings are never exported —
  they are client auth, not topic infrastructure.
- **No injection surface.** Output is Terraform JSON, never templated HCL.
  `${...}` expressions are emitted only by validated variable/reference
  classes; `${`/`%{` in user data is escaped per spec.
- **Filesystem jail.** Output paths are relative, `.tf.json`-suffixed,
  traversal- and symlink-checked against the project dir, written atomically.
  Files lacking the generated-file header are never overwritten; there is no
  `--force`.
- **Compile-time failure.** Duplicate names/paths, unknown providers, bad
  globs, unresolvable placeholders fail the container build, not the export.

## CI recipe

```yaml
- name: Terraform drift check
  run: php bin/console vortos:iac:export --check
```

Run `terraform validate` / `plan` against the generated files in your infra
pipeline as usual.

## Adding a new resource family (maintainers)

Four files, mirroring `Exporter/Kafka/`:

1. **Definition** — extend `AbstractExporterDefinition`; `compileSpec()` reads
   compiled container parameters and returns a static spec (use
   `PlaceholderTranslator::translate()` for any value that may hold an
   `%env(...)%` placeholder; throw `\LogicException` for misconfiguration).
2. **Exporter** — implement `ExporterInterface`; map the spec onto a
   `TerraformDocument` (`SpecValue::decode()` turns spec values into
   variables/references). Pure transform: no I/O.
3. **Provider mapper(s)** — one per Terraform provider if the family supports
   several (see `KafkaTopicMapperInterface`).
4. **Golden-file tests** — compile a fixture container, export, byte-compare
   rendered output (see `KafkaTopicsExportTest`).

Register the exporter service in `IacExtension` and add it to the
`ExportRunner` exporter map.
