<?php

declare(strict_types=1);

namespace Vortos\Security\DependencyInjection;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Vortos\Config\DependencyInjection\ConfigExtension;
use Vortos\Config\Stub\ConfigStub;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Security\Contract\EncryptionInterface;
use Vortos\Security\Contract\SecretsInterface;
use Vortos\Security\Cors\Middleware\CorsMiddleware;
use Vortos\Security\Csrf\CsrfTokenService;
use Vortos\Security\Csrf\Middleware\CsrfMiddleware;
use Vortos\Security\Encryption\EncryptionService;
use Vortos\Security\Encryption\KeyDerivationService;
use Vortos\Security\Event\SecurityEventDispatcher;
use Vortos\Security\Headers\ContentSecurityPolicyBuilder;
use Vortos\Security\Headers\Middleware\SecurityHeadersMiddleware;
use Vortos\Security\IpFilter\IpResolver;
use Vortos\Security\IpFilter\Middleware\IpFilterMiddleware;
use Vortos\Security\Masking\DataMaskingProcessor;
use Vortos\Security\Password\Breach\HaveIBeenPwnedBreachCheck;
use Vortos\Security\Password\PasswordPolicyService;
use Vortos\Security\Password\Rule\CommonPasswordRule;
use Vortos\Security\Password\Rule\ComplexityRule;
use Vortos\Security\Password\Rule\MaxLengthRule;
use Vortos\Security\Password\Rule\MinLengthRule;
use Vortos\Security\Secrets\AwsSsmSecretsProvider;
use Vortos\Security\Secrets\EnvSecretsProvider;
use Vortos\Security\Secrets\VaultSecretsProvider;
use Vortos\Security\Signing\Middleware\RequestSignatureMiddleware;
use Vortos\Security\Signing\SignatureVerifier;

/**
 * Wires all vortos-security services.
 *
 * Loads config/security.php then config/{env}/security.php.
 *
 * Middleware priority map (integrated with Auth and Authorization):
 *
 *   100  SecurityHeadersMiddleware  — adds security headers to every response
 *    95  CorsMiddleware             — preflight handling before auth
 *    90  IpFilterMiddleware         — reject blocked IPs before auth
 *    75  RequestSignatureMiddleware — webhook HMAC validation before auth
 *    20  CsrfMiddleware             — CSRF token validation after routing, before auth
 *     7  RateLimitMiddleware (IP)   — (Auth module)
 *     6  AuthMiddleware             — (Auth module)
 *     5  TwoFactorMiddleware        — (Auth module)
 *     4  RateLimitMiddleware (User) — (Auth module)
 *     3  AuthorizationMiddleware    — (Authorization module)
 *     0  QuotaMiddleware            — (Auth module)
 *
 * ## Opt-in features
 *
 *   Encryption — registered when encryption.enabled = true
 *   Secrets    — VaultSecretsProvider or AwsSsmSecretsProvider registered when driver ≠ EnvSecretsProvider
 *   Masking    — DataMaskingProcessor injected into Monolog when data_masking.enabled = true
 */
final class SecurityExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_security';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');
        $env        = $container->getParameter('kernel.env');

        $config = new VortosSecurityConfig();

        $base = $projectDir . '/config/security.php';
        if (file_exists($base)) {
            (require $base)($config);
        }

        $envFile = $projectDir . '/config/' . $env . '/security.php';
        if (file_exists($envFile)) {
            (require $envFile)($config);
        }

        $resolved = $this->processConfiguration(new Configuration(), [$config->toArray()]);

        $this->registerSecurityEventDispatcher($container, $resolved);
        $this->registerHeadersMiddleware($container, $resolved['headers']);
        $this->registerCorsMiddleware($container, $resolved['cors']);
        $this->registerIpFilterMiddleware($container, $resolved['ip_filter']);
        $this->registerCsrfMiddleware($container, $resolved['csrf']);
        $this->registerRequestSignatureMiddleware($container);
        $this->registerPasswordPolicy($container, $resolved['password_policy']);
        $this->registerSecretsProvider($container, $resolved['secrets']);
        $this->registerEncryption($container, $resolved['encryption'], $resolved['secrets']);
        $this->registerDataMasking($container, $resolved['data_masking']);

        $container->register('vortos.config_stub.security', ConfigStub::class)
            ->setArguments(['security', __DIR__ . '/../stubs/security.php'])
            ->addTag(ConfigExtension::STUB_TAG)
            ->setPublic(false);
    }

    private function registerSecurityEventDispatcher(ContainerBuilder $container, array $resolved): void
    {
        $container->register(SecurityEventDispatcher::class, SecurityEventDispatcher::class)
            ->setArgument('$logger', new Reference('vortos.logger.security', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$metrics', new Reference(MetricsInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setShared(true)
            ->setPublic(true);
    }

    private function registerHeadersMiddleware(ContainerBuilder $container, array $resolved): void
    {
        $cspHeader = null;
        if (isset($resolved['csp']) && ($resolved['csp']['enabled'] ?? true)) {
            $container->register(ContentSecurityPolicyBuilder::class, ContentSecurityPolicyBuilder::class)
                ->setArgument('$config', $resolved['csp'])
                ->setShared(true)
                ->setPublic(false);
            $cspHeader = new Reference(ContentSecurityPolicyBuilder::class);
        }

        $container->register(SecurityHeadersMiddleware::class, SecurityHeadersMiddleware::class)
            ->setArguments([
                $resolved,
                $cspHeader,
            ])
            ->addTag('kernel.event_subscriber')
            ->setShared(true)
            ->setPublic(true);
    }

    private function registerCorsMiddleware(ContainerBuilder $container, array $resolved): void
    {
        $container->register(CorsMiddleware::class, CorsMiddleware::class)
            ->setArgument('$config', $resolved)
            ->setArgument('$routeMap', []) // filled by CorsCompilerPass (routes with #[Cors])
            ->addTag('kernel.event_subscriber')
            ->setShared(true)
            ->setPublic(true);
    }

    private function registerIpFilterMiddleware(ContainerBuilder $container, array $resolved): void
    {
        $container->register(IpResolver::class, IpResolver::class)
            ->setArgument('$trustedProxies', $resolved['trusted_proxies'])
            ->setShared(true)
            ->setPublic(false);

        $container->register(IpFilterMiddleware::class, IpFilterMiddleware::class)
            ->setArguments([
                new Reference(IpResolver::class),
                new Reference(SecurityEventDispatcher::class),
                $resolved['enabled'],
                $resolved['allowlist'],
                $resolved['denylist'],
                [],  // per-route overrides — filled by IpFilterCompilerPass
            ])
            ->addTag('kernel.event_subscriber')
            ->setShared(true)
            ->setPublic(true);
    }

    private function registerCsrfMiddleware(ContainerBuilder $container, array $resolved): void
    {
        $container->register(CsrfTokenService::class, CsrfTokenService::class)
            ->setArguments([
                $resolved['cookie_name'],
                $resolved['header_name'],
                $resolved['token_length'],
                $resolved['cookie_secure'],
                $resolved['cookie_same_site'],
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->register(CsrfMiddleware::class, CsrfMiddleware::class)
            ->setArguments([
                new Reference(CsrfTokenService::class),
                new Reference(SecurityEventDispatcher::class),
                $resolved['enabled'],
                [],  // skip controllers — filled by CsrfCompilerPass
            ])
            ->addTag('kernel.event_subscriber')
            ->setShared(true)
            ->setPublic(true);
    }

    private function registerRequestSignatureMiddleware(ContainerBuilder $container): void
    {
        $container->register(SignatureVerifier::class, SignatureVerifier::class)
            ->setShared(true)
            ->setPublic(false);

        $container->register(RequestSignatureMiddleware::class, RequestSignatureMiddleware::class)
            ->setArguments([
                new Reference(SignatureVerifier::class),
                new Reference(SecurityEventDispatcher::class),
                [],  // routeMap — filled by RequestSignatureCompilerPass
            ])
            ->addTag('kernel.event_subscriber')
            ->setShared(true)
            ->setPublic(true);
    }

    private function registerPasswordPolicy(ContainerBuilder $container, array $resolved): void
    {
        $rules = [];

        $container->register(MinLengthRule::class, MinLengthRule::class)
            ->setArgument('$minLength', $resolved['min_length'])
            ->setShared(true)->setPublic(false);
        $rules[] = new Reference(MinLengthRule::class);

        $container->register(MaxLengthRule::class, MaxLengthRule::class)
            ->setArgument('$maxLength', $resolved['max_length'])
            ->setShared(true)->setPublic(false);
        $rules[] = new Reference(MaxLengthRule::class);

        $container->register(ComplexityRule::class, ComplexityRule::class)
            ->setArguments([
                $resolved['require_uppercase'],
                $resolved['require_lowercase'],
                $resolved['require_digit'],
                $resolved['require_special'],
            ])
            ->setShared(true)->setPublic(false);
        $rules[] = new Reference(ComplexityRule::class);

        if ($resolved['check_common']) {
            $container->register(CommonPasswordRule::class, CommonPasswordRule::class)
                ->setShared(true)->setPublic(false);
            $rules[] = new Reference(CommonPasswordRule::class);
        }

        if ($resolved['hibp_enabled']) {
            $container->register(HaveIBeenPwnedBreachCheck::class, HaveIBeenPwnedBreachCheck::class)
                ->setShared(true)->setPublic(false);
            $rules[] = new Reference(HaveIBeenPwnedBreachCheck::class);
        }

        $container->register(PasswordPolicyService::class, PasswordPolicyService::class)
            ->setArgument('$rules', $rules)
            ->setShared(true)
            ->setPublic(true);
    }

    private function registerSecretsProvider(ContainerBuilder $container, array $resolved): void
    {
        $container->register(EnvSecretsProvider::class, EnvSecretsProvider::class)
            ->setShared(true)->setPublic(false);

        $driver = $resolved['driver'];

        if ($driver === VaultSecretsProvider::class) {
            $container->register(VaultSecretsProvider::class, VaultSecretsProvider::class)
                ->setArguments([
                    $resolved['vault_addr'],
                    $resolved['vault_token'],
                    $resolved['role_id'],
                    $resolved['secret_id'],
                    $resolved['cache_ttl'],
                ])
                ->setShared(true)->setPublic(false);
            $container->setAlias(SecretsInterface::class, VaultSecretsProvider::class)->setPublic(true);
            return;
        }

        if ($driver === AwsSsmSecretsProvider::class) {
            if (!class_exists(\Aws\Ssm\SsmClient::class)) {
                throw new \RuntimeException(
                    'vortos-security: AwsSsmSecretsProvider requires aws/aws-sdk-php. '
                    . 'Run: composer require aws/aws-sdk-php'
                );
            }
            $container->register(AwsSsmSecretsProvider::class, AwsSsmSecretsProvider::class)
                ->setArguments([
                    $resolved['aws_region'],
                    $resolved['aws_prefix'],
                    $resolved['cache_ttl'],
                ])
                ->setShared(true)->setPublic(false);
            $container->setAlias(SecretsInterface::class, AwsSsmSecretsProvider::class)->setPublic(true);
            return;
        }

        $container->setAlias(SecretsInterface::class, EnvSecretsProvider::class)->setPublic(true);
    }

    private function registerEncryption(ContainerBuilder $container, array $encConfig, array $secretsConfig): void
    {
        if (!$encConfig['enabled']) {
            return;
        }

        $container->register(KeyDerivationService::class, KeyDerivationService::class)
            ->setArgument('$masterKeyEnv', $encConfig['master_key_env'])
            ->setArgument('$secrets', new Reference(SecretsInterface::class))
            ->setShared(true)->setPublic(false);

        $container->register(EncryptionService::class, EncryptionService::class)
            ->setArguments([
                new Reference(KeyDerivationService::class),
                $encConfig['algorithm'],
            ])
            ->setShared(true)->setPublic(true);

        $container->setAlias(EncryptionInterface::class, EncryptionService::class)->setPublic(true);
    }

    private function registerDataMasking(ContainerBuilder $container, array $resolved): void
    {
        if (!$resolved['enabled']) {
            return;
        }

        $strategyClass = $resolved['default_strategy'];
        if (!$container->hasDefinition($strategyClass)) {
            $container->register($strategyClass, $strategyClass)
                ->setShared(true)->setPublic(false);
        }

        $container->register(DataMaskingProcessor::class, DataMaskingProcessor::class)
            ->setArgument('$strategy', new Reference($strategyClass))
            ->setShared(true)->setPublic(false);

        // Inject into security logger channel
        if ($container->hasDefinition('vortos.logger.security')) {
            $container->getDefinition('vortos.logger.security')
                ->addMethodCall('pushProcessor', [new Reference(DataMaskingProcessor::class)]);
        }

        // Optionally inject into all other channels
        if ($resolved['all_channels']) {
            foreach (['app', 'http', 'cqrs', 'messaging', 'cache', 'query'] as $channel) {
                $id = 'vortos.logger.' . $channel;
                if ($container->hasDefinition($id)) {
                    $container->getDefinition($id)
                        ->addMethodCall('pushProcessor', [new Reference(DataMaskingProcessor::class)]);
                }
            }
        }
    }
}
