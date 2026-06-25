# vortos/vortos-ops-kit

The **one uniform swappable-driver pattern** every Vortos deploy/ops concern reuses
(roadmap §10). Build it once here so concerns 2–26 never reinvent registry /
compiler-pass / capability / TCK subtly differently — the guard against "a mess all
over the place."

## What's in the box

| Primitive | Purpose |
|---|---|
| `Attribute\AsDriver` | Declares a driver's stable string key (read once, at compile time). |
| `Driver\DriverInterface` | The universal contract: every driver reports its `capabilities()`. A concern's port extends this. |
| `Driver\Capability\CapabilityDescriptor` | What a driver declares it can do — versioned, canonically serializable, machine-diffable. The single source for config-time validation. |
| `Driver\Capability\RequiredCapabilities` + `CapabilityValidator` | What a selection requires, and the fail-closed assertion that runs at config-validation time (`CapabilityMismatchException` with an actionable message). |
| `Driver\TaggedDriverRegistry` | Lazy, key→driver registry backed by a compile-time-built ServiceLocator. Zero runtime reflection. |
| `Driver\DependencyInjection\CollectDriversCompilerPass` | Reusable, late-priority collecting pass. Validates keys (lower-kebab, unique) at build time. |
| `Testing\ConformanceTestCase` | The TCK base. Every port ships a concrete subclass; every driver extends it — including negative cases (unsupported ⇒ throws, malformed ⇒ rejected). |
| `Architecture\AgnosticismScanner` + `Testing\AgnosticismLintTestCase` | AST-based lint (via nikic/php-parser): no provider name outside a `Driver\` namespace or the composition root. |
| `PHPStan\AgnosticismRule` | The same lint as a first-class PHPStan rule, sharing `ProviderNameMatcher` (wire it once a `phpstan.neon` exists). |
| `Console\MakeDriverCommand` | `vortos:make:driver <concern> <name>` — scaffolds impl + `#[AsDriver]` + conformance test (`--split` adds a full composer package). |

## Adding a concern (the shape every concern follows)

```php
// 1. A port that extends DriverInterface
interface DeployTargetInterface extends DriverInterface { /* ... */ }

// 2. A typed registry
final class DeployTargetRegistry extends TaggedDriverRegistry {
    public function __construct(ContainerInterface $drivers) { parent::__construct('deploy', $drivers); }
    public function target(string $key): DeployTargetInterface { return $this->get($key); }
}

// 3. A collecting pass
final class CollectDeployTargetsPass extends CollectDriversCompilerPass {
    public function __construct() { parent::__construct('vortos.deploy.target', 'vortos.deploy.target_locator', 'deploy'); }
}

// 4. In the concern's DI extension:
//    - register the ServiceLocator (tag container.service_locator) + the registry
//    - registerForAutoconfiguration(DeployTargetInterface::class)->addTag('vortos.deploy.target')
//    - in Package::build(): CollectDriversCompilerPass::register($container, new CollectDeployTargetsPass())

// 5. A conformance TCK base for the port (extends ConformanceTestCase), and an
//    agnosticism test (extends AgnosticismLintTestCase).
```

A driver is then just: implement the port + `#[AsDriver(key: '…')]`. `vortos:make:driver`
writes that for you.

## Tests

```
docker compose exec backend ./vendor/bin/phpunit --testsuite OpsKit
```
