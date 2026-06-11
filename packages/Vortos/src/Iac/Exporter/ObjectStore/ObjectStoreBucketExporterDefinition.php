<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\ObjectStore;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Iac\Definition\AbstractExporterDefinition;
use Vortos\Iac\Export\PlaceholderTranslator;

/**
 * Exports the object-store bucket declared by vortos-object-store as a
 * Terraform bucket resource. Reads the compiled vortos_object_store.*
 * parameters — nothing new to declare on the object-store side.
 *
 * Example:
 *   #[RegisterTerraformExporter]
 *   public function bucket(): ObjectStoreBucketExporterDefinition
 *   {
 *       return ObjectStoreBucketExporterDefinition::create('app-bucket')
 *           ->provider(ObjectStoreProvider::CloudflareR2)
 *           ->outputFile('infra/bucket.tf.json');
 *   }
 */
final class ObjectStoreBucketExporterDefinition extends AbstractExporterDefinition
{
    private ?ObjectStoreProvider $provider = null;

    public function provider(ObjectStoreProvider $provider): static
    {
        $this->provider = $provider;
        return $this;
    }

    public function exporterClass(): string
    {
        return ObjectStoreBucketExporter::class;
    }

    public function compileSpec(ContainerBuilder $container): array
    {
        if ($this->provider === null) {
            throw new \LogicException(sprintf(
                "Object-store exporter '%s' declares no provider(). Choose ObjectStoreProvider::Aws or ObjectStoreProvider::CloudflareR2.",
                $this->name,
            ));
        }

        if (!$container->hasParameter('vortos_object_store.bucket.name')) {
            throw new \LogicException(sprintf(
                "Object-store exporter '%s' requires the vortos-object-store package (bucket parameters not found).",
                $this->name,
            ));
        }

        $context = sprintf("Object-store exporter '%s'", $this->name);

        $spec = [
            'provider' => $this->provider->value,
            'label' => str_replace('-', '_', $this->name),
            'bucket' => PlaceholderTranslator::translate(
                $container->getParameter('vortos_object_store.bucket.name'),
                $container,
                $context,
            ),
            'region' => PlaceholderTranslator::translate(
                $container->hasParameter('vortos_object_store.region')
                    ? $container->getParameter('vortos_object_store.region')
                    : null,
                $container,
                $context,
            ),
        ];

        if ($this->provider === ObjectStoreProvider::CloudflareR2) {
            $spec['account_id'] = PlaceholderTranslator::translate(
                $container->hasParameter('vortos_object_store.client.account_id')
                    ? $container->getParameter('vortos_object_store.client.account_id')
                    : null,
                $container,
                $context,
            );

            if ($spec['account_id'] === null || $spec['account_id'] === '') {
                throw new \LogicException(sprintf(
                    '%s: Cloudflare R2 requires vortos_object_store.client.account_id to be configured.',
                    $context,
                ));
            }
        }

        return $spec;
    }
}
