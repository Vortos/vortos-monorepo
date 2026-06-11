<?php

declare(strict_types=1);

namespace Vortos\Iac\Definition;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Iac\Export\PathPolicy;

/**
 * Base for all Terraform exporter definitions, built fluently inside an
 * InfraConfig class.
 *
 * A definition is the COMPILE-TIME half of an exporter: at container build
 * time the compiler pass calls compileSpec(), which reads already-compiled
 * framework parameters (vortos.transports, …) and produces a fully static
 * spec — every Env placeholder already translated to a Terraform variable
 * spec, every filter already applied. The runtime half (ExporterInterface)
 * only maps that spec onto a Terraform document.
 *
 * Adding a new resource family = one definition subclass + one exporter
 * + provider mapper(s) + golden-file tests. See the package README.
 */
abstract class AbstractExporterDefinition
{
    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/';

    protected string $name;
    protected string $outputFile = '';
    protected ?string $variablesFile = null;

    /** @var list<string> */
    protected array $allowedLiteralPaths = [];

    final protected function __construct(string $name)
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new \LogicException(sprintf(
                "Invalid exporter name '%s' — use lowercase letters, digits, '-' and '_'.",
                $name,
            ));
        }

        $this->name = $name;
    }

    public static function create(string $name): static
    {
        return new static($name);
    }

    final public function getName(): string
    {
        return $this->name;
    }

    /** Target file, relative to the project directory, ending in .tf.json. */
    public function outputFile(string $path): static
    {
        $this->outputFile = $path;
        return $this;
    }

    /** Where variable blocks go. Defaults to '<output>_variables.tf.json'. */
    public function variablesFile(string $path): static
    {
        $this->variablesFile = $path;
        return $this;
    }

    /**
     * Opt-out of the secret gate for one dotted attribute path whose name
     * matches the secret pattern but genuinely is not secret. Loud and
     * greppable by design.
     */
    public function allowLiteral(string $attributePath): static
    {
        $this->allowedLiteralPaths[] = $attributePath;
        return $this;
    }

    /** FQCN of the runtime ExporterInterface service that renders this spec. */
    abstract public function exporterClass(): string;

    /**
     * Builds the static export spec from container state. Runs at compile
     * time — throw \LogicException for anything wrong, so it fails the
     * build, not the export run.
     *
     * @return array<string, mixed>
     */
    abstract public function compileSpec(ContainerBuilder $container): array;

    /** @return array<string, mixed> one entry of the vortos.iac.exports parameter */
    final public function toExportEntry(ContainerBuilder $container): array
    {
        if ($this->outputFile === '') {
            throw new \LogicException(sprintf(
                "Exporter '%s' declares no outputFile(). Every exporter must name its target file.",
                $this->name,
            ));
        }

        PathPolicy::validate($this->outputFile);

        $variablesFile = $this->variablesFile
            ?? preg_replace('/\.tf\.json$/', '', $this->outputFile) . '_variables.tf.json';

        PathPolicy::validate($variablesFile);

        return [
            'name' => $this->name,
            'exporter' => $this->exporterClass(),
            'output_file' => $this->outputFile,
            'variables_file' => $variablesFile,
            'allowed_literals' => $this->allowedLiteralPaths,
            'spec' => $this->compileSpec($container),
        ];
    }
}
