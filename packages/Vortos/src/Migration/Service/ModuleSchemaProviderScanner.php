<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Vortos\Foundation\Module\ModulePathResolver;
use Vortos\Migration\Schema\ModuleSchemaProviderInterface;

final class ModuleSchemaProviderScanner
{
    /** @var list<string> */
    private array $additionalPatterns = [];

    public function __construct(
        private readonly ModulePathResolver $resolver,
        private readonly string $projectDir,
    ) {
    }

    public function addScanPath(string $globPattern): void
    {
        $this->additionalPatterns[] = $globPattern;
    }

    /**
     * @return list<array{
     *     module: string,
     *     filename: string,
     *     path: string,
     *     relative: string,
     *     provider: ModuleSchemaProviderInterface
     * }>
     */
    public function scan(): array
    {
        $providers = [];
        $seen = [];

        foreach ($this->resolver->findInModules('Resources/migrations/*.php') as $absolutePath) {
            $this->addProviderFile($providers, $seen, $absolutePath);
        }

        foreach ($this->additionalPatterns as $pattern) {
            $fullPattern = str_starts_with($pattern, '/') ? $pattern : $this->projectDir . '/' . $pattern;

            foreach (glob($fullPattern) ?: [] as $absolutePath) {
                $this->addProviderFile($providers, $seen, $absolutePath);
            }
        }

        usort($providers, static fn(array $a, array $b) => strcmp($a['filename'], $b['filename']));

        return $providers;
    }

    /**
     * @param list<array<string, mixed>> $providers
     * @param array<string, true> $seen
     */
    private function addProviderFile(array &$providers, array &$seen, string $absolutePath): void
    {
        $real = realpath($absolutePath);

        if ($real !== false && isset($seen[$real])) {
            return;
        }

        if ($real !== false) {
            $seen[$real] = true;
        }

        $provider = $this->loadProvider($absolutePath);
        $relative = $this->toRelative($absolutePath);

        $providers[] = [
            'module' => $provider->module(),
            'filename' => basename($absolutePath),
            'path' => $absolutePath,
            'relative' => $relative,
            'provider' => $provider,
        ];
    }

    private function loadProvider(string $path): ModuleSchemaProviderInterface
    {
        $provider = (static function (string $path): mixed {
            return require $path;
        })($path);

        if (!$provider instanceof ModuleSchemaProviderInterface) {
            throw new \RuntimeException(sprintf(
                'Module migration schema provider "%s" must return an instance of %s.',
                $path,
                ModuleSchemaProviderInterface::class,
            ));
        }

        return $provider;
    }

    private function toRelative(string $absolutePath): string
    {
        return ltrim(str_replace($this->projectDir, '', $absolutePath), '/');
    }
}
