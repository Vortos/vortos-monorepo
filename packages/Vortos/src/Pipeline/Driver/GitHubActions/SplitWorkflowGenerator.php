<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Driver\GitHubActions;

use Vortos\Pipeline\Builder\KnownActionFactory;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Model\SplitPackage;

final class SplitWorkflowGenerator
{
    /**
     * @param list<SplitPackage> $packages
     * @return array<string, mixed>
     */
    public function generate(array $packages, PipelineDefinition $definition): array
    {
        $checkout = KnownActionFactory::checkout();
        $setupPhp = KnownActionFactory::setupPhp();
        $setupNode = KnownActionFactory::setupNode();
        $split = KnownActionFactory::monorepoSplit();

        $workflow = [];
        $workflow['name'] = 'Monorepo Split';

        $workflow['on'] = [
            'push' => [
                'branches' => ['main'],
                'tags' => ['*'],
            ],
        ];

        $workflow['permissions'] = ['contents' => 'read'];

        $jobs = [];

        $jobs['tests'] = [
            'runs-on' => 'ubuntu-latest',
            'timeout-minutes' => $definition->defaultTimeoutMinutes,
            'steps' => [
                ['name' => 'Checkout', 'uses' => $checkout->toCommentedString()],
                ['name' => 'Setup PHP', 'uses' => $setupPhp->toCommentedString(), 'with' => [
                    'php-version' => $definition->phpVersion,
                    'extensions' => implode(', ', $definition->phpExtensions),
                    'coverage' => 'none',
                ]],
                ['name' => 'Install dependencies', 'run' => 'composer install --no-interaction --prefer-dist --ignore-platform-reqs'],
                ['name' => 'Run tests', 'run' => './vendor/bin/phpunit --testdox'],
            ],
        ];

        if ($definition->benchmark) {
            $jobs['benchmark'] = [
                'needs' => ['tests'],
                'runs-on' => 'ubuntu-latest',
                'timeout-minutes' => $definition->defaultTimeoutMinutes,
                'steps' => [
                    ['name' => 'Checkout', 'uses' => $checkout->toCommentedString()],
                    ['name' => 'Setup PHP', 'uses' => $setupPhp->toCommentedString(), 'with' => [
                        'php-version' => $definition->phpVersion,
                        'extensions' => implode(', ', [...$definition->phpExtensions, 'opcache']),
                        'coverage' => 'none',
                        'ini-values' => 'opcache.enable_cli=1,opcache.jit=tracing,opcache.jit_buffer_size=64M',
                    ]],
                    ['name' => 'Install dependencies', 'run' => 'composer install --no-interaction --prefer-dist --ignore-platform-reqs'],
                    ['name' => 'Run benchmarks (SLO assertion)', 'run' => './vendor/bin/phpbench run packages/Vortos/src/FeatureFlags/Tests/Benchmark --report=aggregate --retry-threshold=5'],
                ],
            ];
        }

        if ($definition->uiBuild && $definition->nodeVersion !== null) {
            $uiPath = $definition->uiBuildPath ?? 'packages/feature-flags-admin';
            $jobs['ui-build'] = [
                'needs' => ['tests'],
                'runs-on' => 'ubuntu-latest',
                'timeout-minutes' => $definition->defaultTimeoutMinutes,
                'steps' => [
                    ['name' => 'Checkout', 'uses' => $checkout->toCommentedString()],
                    ['name' => 'Setup Node', 'uses' => $setupNode->toCommentedString(), 'with' => [
                        'node-version' => $definition->nodeVersion,
                        'cache' => 'npm',
                        'cache-dependency-path' => $uiPath . '/package.json',
                    ]],
                    ['name' => 'Install JS dependencies', 'run' => 'npm install', 'working-directory' => $uiPath],
                    ['name' => 'Type-check', 'run' => 'npm run typecheck', 'working-directory' => $uiPath],
                    ['name' => 'Build islands bundle', 'run' => 'npm run build', 'working-directory' => $uiPath],
                ],
            ];
        }

        $splitNeeds = ['tests'];
        if ($definition->uiBuild && $definition->nodeVersion !== null) {
            $splitNeeds[] = 'ui-build';
        }

        $matrix = array_map(
            static fn (SplitPackage $p): array => $p->toArray(),
            $packages,
        );

        $jobs['split'] = [
            'needs' => $splitNeeds,
            'runs-on' => 'ubuntu-latest',
            'strategy' => [
                'fail-fast' => false,
                'matrix' => [
                    'package' => $matrix,
                ],
            ],
            'steps' => [
                [
                    'name' => 'Checkout',
                    'uses' => $checkout->toCommentedString(),
                    'with' => ['fetch-depth' => '0'],
                ],
                [
                    'name' => 'Split',
                    'uses' => $split->toCommentedString(),
                    'env' => ['GITHUB_TOKEN' => '${{ secrets.MONOREPO_SPLIT_TOKEN }}'],
                    'with' => [
                        'package_directory' => '${{ matrix.package.local_path }}',
                        'repository_organization' => 'Vortos',
                        'repository_name' => '${{ matrix.package.split_repository }}',
                        'user_name' => 'Sachintha De Silva',
                        'user_email' => 'yslaksura@gmail.com',
                        'tag' => '${{ github.ref_type == \'tag\' && github.ref_name || \'\' }}',
                        'branch' => '${{ github.ref_type == \'branch\' && github.ref_name || \'main\' }}',
                    ],
                ],
            ],
        ];

        $workflow['jobs'] = $jobs;

        return $workflow;
    }
}
