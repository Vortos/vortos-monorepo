<?php

declare(strict_types=1);

namespace Vortos\Iac\Export;

use Vortos\Iac\Exception\IacException;

/**
 * Orchestrates one export run: spec entries → exporters → rendered
 * documents → SafeFileWriter (or drift check, or stdout for dry runs).
 */
final class ExportRunner
{
    public function __construct(
        /** @var array<class-string, ExporterInterface> */
        private readonly array $exporters,
        /** @var list<array<string, mixed>> the compiled vortos.iac.exports parameter */
        private readonly array $exports,
        private readonly SafeFileWriter $writer,
    ) {}

    /**
     * @param 'write'|'check'|'dry-run' $mode
     * @return list<array{name: string, file: string, resources: int, outcome: FileOutcome, content: string}>
     */
    public function run(string $mode): array
    {
        $results = [];

        foreach ($this->exports as $entry) {
            $exporterClass = $entry['exporter'];
            $exporter = $this->exporters[$exporterClass] ?? throw new IacException(sprintf(
                "No exporter service registered for '%s'. Is the providing package installed?",
                $exporterClass,
            ));

            $document = $exporter->export($entry);
            $resources = $exporter->countResources($entry);

            // Variables render into their own file, never inline with resources.
            $files = [$entry['output_file'] => $document->render(includeVariables: false)];

            if ($document->hasVariables()) {
                $files[$entry['variables_file']] = $document->variablesDocument()->render();
            }

            foreach ($files as $relativePath => $content) {
                $outcome = match ($mode) {
                    'write' => $this->writer->write($relativePath, $content),
                    'check' => $this->writer->check($relativePath, $content),
                    'dry-run' => FileOutcome::WouldWrite,
                    default => throw new IacException(sprintf("Unknown export mode '%s'.", $mode)),
                };

                $results[] = [
                    'name' => $entry['name'],
                    'file' => $relativePath,
                    'resources' => $resources,
                    'outcome' => $outcome,
                    'content' => $content,
                ];
            }
        }

        return $results;
    }
}
