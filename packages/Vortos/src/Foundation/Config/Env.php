<?php

declare(strict_types=1);

namespace Vortos\Foundation\Config;

/**
 * A typed reference to an environment variable, for use in compile-time
 * config definitions (MessagingConfig transports, etc.).
 *
 * Config definition classes are instantiated at container compile time, so
 * reading $_ENV in them bakes one environment's value into the compiled
 * container — wrong across environments. Env defers resolution to runtime
 * via Symfony env placeholders while keeping the fluent API typed:
 *
 *   KafkaTransportDefinition::create('orders.placed')
 *       ->dsn(Env::string('KAFKA_BROKERS'))
 *       ->partitions(Env::int('KAFKA_PARTITIONS', default: 12))
 *       ->replicationFactor(Env::int('KAFKA_REPLICATION_FACTOR', default: 3))
 *
 * The compiler pass converts each Env into a '%env(...)%' placeholder (and
 * registers the default as a container parameter), so the container resolves
 * the live value when the consuming service is instantiated — uniform with
 * plain '%env(VAR)%' strings, no $_ENV reads anywhere.
 *
 * Same philosophy as the #[Value] attribute: env access is a declared
 * reference, never an inline read.
 */
final readonly class Env
{
    /** Container parameter prefix under which defaults are registered. */
    public const DEFAULT_PARAMETER_PREFIX = 'vortos.env_default.';

    private function __construct(
        public string $name,
        /** Symfony env processor prefix: '' (string), 'int', 'float', 'bool' */
        public string $type,
        public string|int|float|bool|null $default,
    ) {
        if ($name === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException(
                sprintf("Invalid environment variable name '%s'.", $name),
            );
        }
    }

    public static function string(string $name, ?string $default = null): self
    {
        return new self($name, '', $default);
    }

    public static function int(string $name, ?int $default = null): self
    {
        return new self($name, 'int', $default);
    }

    public static function float(string $name, ?float $default = null): self
    {
        return new self($name, 'float', $default);
    }

    public static function bool(string $name, ?bool $default = null): self
    {
        return new self($name, 'bool', $default);
    }

    public function hasDefault(): bool
    {
        return $this->default !== null;
    }

    /**
     * Name of the container parameter holding the default value.
     * Whoever converts the Env (compiler pass) must register it.
     */
    public function defaultParameterName(): string
    {
        return self::DEFAULT_PARAMETER_PREFIX . $this->name;
    }

    /**
     * The Symfony env placeholder this reference compiles to.
     *
     *   Env::int('X')              → %env(int:X)%
     *   Env::int('X', default: 3)  → %env(int:default:vortos.env_default.X:X)%
     *   Env::string('Y')           → %env(Y)%
     */
    public function toPlaceholder(): string
    {
        $processors = [];

        if ($this->type !== '') {
            $processors[] = $this->type;
        }

        if ($this->hasDefault()) {
            $processors[] = 'default';
            $processors[] = $this->defaultParameterName();
        }

        $processors[] = $this->name;

        return '%env(' . implode(':', $processors) . ')%';
    }
}
