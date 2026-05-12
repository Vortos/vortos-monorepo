<?php

declare(strict_types=1);

namespace Vortos\Observability\Service;

final class ObservabilityTemplatePublisher
{
    public function __construct(private readonly ObservabilityTemplateRegistry $registry) {}

    /**
     * @return list<string>
     */
    public function available(): array
    {
        return $this->registry->names();
    }

    public function publish(string $projectDir, string $stackName, bool $force = false, bool $dryRun = false): ObservabilityPublishResult
    {
        $stack = $this->registry->get($stackName);
        if ($stack === null) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown observability stack "%s". Available: %s',
                $stackName,
                implode(', ', $this->available()),
            ));
        }

        $published = [];
        $skipped = [];

        foreach ($stack->files as $relativePath) {
            $source = $this->registry->sourcePath($relativePath);
            $targetRelative = 'observability/' . $relativePath;
            $target = $projectDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $targetRelative);

            $contents = (string) file_get_contents($source);

            if (is_file($target) && hash('sha256', $contents) === hash_file('sha256', $target)) {
                $skipped[] = $targetRelative;
                continue;
            }

            if (is_file($target) && !$force) {
                $skipped[] = $targetRelative;
                continue;
            }

            if (!$dryRun) {
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0755, true);
                }

                file_put_contents($target, $contents);
            }

            $published[] = $targetRelative;
        }

        return new ObservabilityPublishResult($published, $skipped);
    }
}

