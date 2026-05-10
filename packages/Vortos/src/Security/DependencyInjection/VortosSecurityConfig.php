<?php

declare(strict_types=1);

namespace Vortos\Security\DependencyInjection;

use Vortos\Security\Masking\Strategy\MaskPartialStrategy;
use Vortos\Security\Secrets\EnvSecretsProvider;

/**
 * Fluent configuration object for vortos-security.
 *
 * Loaded via require in SecurityExtension::load().
 * Every feature has sensible defaults — no config file is required.
 *
 * ## Standard usage
 *
 * Create config/security.php in your project:
 *
 *   return static function (VortosSecurityConfig $config): void {
 *       $config
 *           ->headers()->hsts(maxAge: 31536000, includeSubDomains: true, preload: true)
 *           ->cors()->origins(['https://app.example.com'])
 *           ->csrf()->cookieSecure(true)
 *           ->ipFilter()->deny(['192.168.1.100'])
 *           ->passwordPolicy()->minLength(14)->hibp(true)
 *           ->encryption()->masterKeyEnv('ENCRYPTION_KEY')
 *           ->secrets()->driver(VaultSecretsProvider::class)->vaultAddr('https://vault:8200');
 *   };
 *
 * ## Dev vs Prod
 *
 *   Create config/dev/security.php to relax settings for local development:
 *
 *   return static function (VortosSecurityConfig $config): void {
 *       $config
 *           ->headers()->hsts(false)->csp()->reportOnly(true)
 *           ->cors()->origins(['*'])
 *           ->csrf()->enabled(false)
 *           ->ipFilter()->enabled(false);
 *   };
 */
final class VortosSecurityConfig
{
    private HeadersConfig $headers;
    private CorsConfig $cors;
    private CsrfConfig $csrf;
    private IpFilterConfig $ipFilter;
    private PasswordPolicyConfig $passwordPolicy;
    private EncryptionConfig $encryption;
    private SecretsConfig $secrets;
    private DataMaskingConfig $dataMasking;

    public function __construct()
    {
        $this->headers       = new HeadersConfig();
        $this->cors          = new CorsConfig();
        $this->csrf          = new CsrfConfig();
        $this->ipFilter      = new IpFilterConfig();
        $this->passwordPolicy = new PasswordPolicyConfig();
        $this->encryption    = new EncryptionConfig();
        $this->secrets       = new SecretsConfig();
        $this->dataMasking   = new DataMaskingConfig();
    }

    public function headers(): HeadersConfig
    {
        return $this->headers;
    }

    public function cors(): CorsConfig
    {
        return $this->cors;
    }

    public function csrf(): CsrfConfig
    {
        return $this->csrf;
    }

    public function ipFilter(): IpFilterConfig
    {
        return $this->ipFilter;
    }

    public function passwordPolicy(): PasswordPolicyConfig
    {
        return $this->passwordPolicy;
    }

    public function encryption(): EncryptionConfig
    {
        return $this->encryption;
    }

    public function secrets(): SecretsConfig
    {
        return $this->secrets;
    }

    public function dataMasking(): DataMaskingConfig
    {
        return $this->dataMasking;
    }

    /** @internal Used by SecurityExtension */
    public function toArray(): array
    {
        return [
            'headers'        => $this->headers->toArray(),
            'cors'           => $this->cors->toArray(),
            'csrf'           => $this->csrf->toArray(),
            'ip_filter'      => $this->ipFilter->toArray(),
            'password_policy' => $this->passwordPolicy->toArray(),
            'encryption'     => $this->encryption->toArray(),
            'secrets'        => $this->secrets->toArray(),
            'data_masking'   => $this->dataMasking->toArray(),
        ];
    }
}

// ---------------------------------------------------------------------------
// Sub-config objects — returned by the fluent methods above.
// Each has a toArray() for normalisation and is also chainable back up to
// VortosSecurityConfig via parent() when the dev wants to configure multiple
// sub-sections in one chain (not required — just use $config->headers()... then
// $config->cors()... separately).
// ---------------------------------------------------------------------------

final class HeadersConfig
{
    private bool   $hsts               = false;
    private int    $hstsMaxAge         = 31536000;
    private bool   $hstsSubDomains     = true;
    private bool   $hstsPreload        = false;
    private string $xFrameOptions      = 'DENY';
    private bool   $xContentTypeNoSniff = true;
    private string $referrerPolicy     = 'strict-origin-when-cross-origin';
    private array  $permissionsPolicy  = [];
    private string $coep               = 'require-corp';
    private string $coop               = 'same-origin';
    private string $corp               = 'same-origin';
    private ?CspConfig $csp            = null;

    public function hsts(bool $enabled = true, int $maxAge = 31536000, bool $includeSubDomains = true, bool $preload = false): static
    {
        $this->hsts           = $enabled;
        $this->hstsMaxAge     = $maxAge;
        $this->hstsSubDomains = $includeSubDomains;
        $this->hstsPreload    = $preload;
        return $this;
    }

    public function xFrameOptions(string $value): static
    {
        $this->xFrameOptions = $value;
        return $this;
    }

    public function xContentTypeOptions(bool $noSniff = true): static
    {
        $this->xContentTypeNoSniff = $noSniff;
        return $this;
    }

    public function referrerPolicy(string $policy): static
    {
        $this->referrerPolicy = $policy;
        return $this;
    }

    /**
     * Configure Permissions-Policy header.
     *
     * Pass feature => allowed-origins list. Empty list = deny all.
     * Example: ['camera' => [], 'geolocation' => ['self'], 'payment' => ['self', 'https://pay.example.com']]
     *
     * @param array<string, list<string>> $features
     */
    public function permissionsPolicy(array $features): static
    {
        $this->permissionsPolicy = $features;
        return $this;
    }

    public function crossOriginPolicies(string $embedder = 'require-corp', string $opener = 'same-origin', string $resource = 'same-origin'): static
    {
        $this->coep = $embedder;
        $this->coop = $opener;
        $this->corp = $resource;
        return $this;
    }

    public function csp(): CspConfig
    {
        if ($this->csp === null) {
            $this->csp = new CspConfig();
        }
        return $this->csp;
    }

    public function toArray(): array
    {
        return [
            'hsts'                  => $this->hsts,
            'hsts_max_age'          => $this->hstsMaxAge,
            'hsts_sub_domains'      => $this->hstsSubDomains,
            'hsts_preload'          => $this->hstsPreload,
            'x_frame_options'       => $this->xFrameOptions,
            'x_content_type_nosniff' => $this->xContentTypeNoSniff,
            'referrer_policy'       => $this->referrerPolicy,
            'permissions_policy'    => $this->permissionsPolicy,
            'coep'                  => $this->coep,
            'coop'                  => $this->coop,
            'corp'                  => $this->corp,
            'csp'                   => $this->csp?->toArray(),
        ];
    }
}

final class CspConfig
{
    private array  $defaultSrc  = ["'self'"];
    private array  $scriptSrc   = ["'self'"];
    private array  $styleSrc    = ["'self'"];
    private array  $imgSrc      = ["'self'", 'data:'];
    private array  $fontSrc     = ["'self'"];
    private array  $connectSrc  = ["'self'"];
    private array  $frameSrc    = ["'none'"];
    private array  $objectSrc   = ["'none'"];
    private array  $mediaSrc    = ["'self'"];
    private array  $workerSrc   = ["'none'"];
    private string $reportUri   = '';
    private string $reportTo    = '';
    private bool   $reportOnly  = false;
    private array  $extra       = [];

    public function defaultSrc(string ...$values): static  { $this->defaultSrc  = $values; return $this; }
    public function scriptSrc(string ...$values): static   { $this->scriptSrc   = $values; return $this; }
    public function styleSrc(string ...$values): static    { $this->styleSrc    = $values; return $this; }
    public function imgSrc(string ...$values): static      { $this->imgSrc      = $values; return $this; }
    public function fontSrc(string ...$values): static     { $this->fontSrc     = $values; return $this; }
    public function connectSrc(string ...$values): static  { $this->connectSrc  = $values; return $this; }
    public function frameSrc(string ...$values): static    { $this->frameSrc    = $values; return $this; }
    public function objectSrc(string ...$values): static   { $this->objectSrc   = $values; return $this; }
    public function mediaSrc(string ...$values): static    { $this->mediaSrc    = $values; return $this; }
    public function workerSrc(string ...$values): static   { $this->workerSrc   = $values; return $this; }
    public function reportUri(string $uri): static         { $this->reportUri   = $uri; return $this; }
    public function reportTo(string $group): static        { $this->reportTo    = $group; return $this; }

    /** In report-only mode violations are logged but not blocked — ideal for dev or policy rollout. */
    public function reportOnly(bool $enabled = true): static { $this->reportOnly = $enabled; return $this; }

    /** Add a custom directive not covered by named methods. */
    public function directive(string $name, string ...$values): static { $this->extra[$name] = $values; return $this; }

    public function toArray(): array
    {
        return [
            'default_src' => $this->defaultSrc,
            'script_src'  => $this->scriptSrc,
            'style_src'   => $this->styleSrc,
            'img_src'     => $this->imgSrc,
            'font_src'    => $this->fontSrc,
            'connect_src' => $this->connectSrc,
            'frame_src'   => $this->frameSrc,
            'object_src'  => $this->objectSrc,
            'media_src'   => $this->mediaSrc,
            'worker_src'  => $this->workerSrc,
            'report_uri'  => $this->reportUri,
            'report_to'   => $this->reportTo,
            'report_only' => $this->reportOnly,
            'extra'       => $this->extra,
        ];
    }
}

final class CorsConfig
{
    private array  $origins         = [];
    private array  $methods         = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
    private array  $allowedHeaders  = ['Content-Type', 'Authorization', 'X-Requested-With'];
    private array  $exposedHeaders  = [];
    private bool   $credentials     = false;
    private int    $maxAge          = 3600;

    /** @param list<string> $origins Allowed origins. '*' = any origin (dev only). */
    public function origins(array $origins): static { $this->origins = $origins; return $this; }

    public function methods(array $methods): static { $this->methods = $methods; return $this; }

    public function allowedHeaders(array $headers): static { $this->allowedHeaders = $headers; return $this; }

    public function exposedHeaders(array $headers): static { $this->exposedHeaders = $headers; return $this; }

    /** Allow cookies/auth credentials in cross-origin requests. Cannot be used with origins('*'). */
    public function credentials(bool $allow = true): static { $this->credentials = $allow; return $this; }

    public function maxAge(int $seconds): static { $this->maxAge = $seconds; return $this; }

    public function toArray(): array
    {
        return [
            'origins'          => $this->origins,
            'methods'          => $this->methods,
            'allowed_headers'  => $this->allowedHeaders,
            'exposed_headers'  => $this->exposedHeaders,
            'credentials'      => $this->credentials,
            'max_age'          => $this->maxAge,
        ];
    }
}

final class CsrfConfig
{
    private bool   $enabled        = true;
    private string $headerName     = 'X-CSRF-Token';
    private string $cookieName     = 'csrf_token';
    private bool   $cookieSecure   = false;
    private string $cookieSameSite = 'Strict';
    private int    $tokenLength    = 32;

    public function enabled(bool $enabled = true): static { $this->enabled = $enabled; return $this; }

    public function headerName(string $name): static { $this->headerName = $name; return $this; }

    public function cookieName(string $name): static { $this->cookieName = $name; return $this; }

    public function cookieSecure(bool $secure = true): static { $this->cookieSecure = $secure; return $this; }

    public function cookieSameSite(string $sameSite): static { $this->cookieSameSite = $sameSite; return $this; }

    public function tokenLength(int $bytes): static { $this->tokenLength = $bytes; return $this; }

    public function toArray(): array
    {
        return [
            'enabled'          => $this->enabled,
            'header_name'      => $this->headerName,
            'cookie_name'      => $this->cookieName,
            'cookie_secure'    => $this->cookieSecure,
            'cookie_same_site' => $this->cookieSameSite,
            'token_length'     => $this->tokenLength,
        ];
    }
}

final class IpFilterConfig
{
    private bool   $enabled        = false;
    private array  $allowlist      = [];
    private array  $denylist       = [];
    private array  $trustedProxies = ['127.0.0.1', '::1'];

    public function enabled(bool $enabled = true): static { $this->enabled = $enabled; return $this; }

    /** @param list<string> $cidrs CIDR ranges or exact IPs to allow exclusively. When non-empty, all other IPs are denied. */
    public function allow(array $cidrs): static { $this->allowlist = $cidrs; return $this; }

    /** @param list<string> $cidrs CIDR ranges or exact IPs to block. */
    public function deny(array $cidrs): static { $this->denylist = $cidrs; return $this; }

    /** @param list<string> $ips Proxy IPs whose X-Forwarded-For header is trusted. */
    public function trustedProxies(array $ips): static { $this->trustedProxies = $ips; return $this; }

    public function toArray(): array
    {
        return [
            'enabled'         => $this->enabled,
            'allowlist'       => $this->allowlist,
            'denylist'        => $this->denylist,
            'trusted_proxies' => $this->trustedProxies,
        ];
    }
}

final class PasswordPolicyConfig
{
    private int  $minLength          = 12;
    private int  $maxLength          = 128;
    private bool $requireUppercase   = true;
    private bool $requireLowercase   = true;
    private bool $requireDigit       = true;
    private bool $requireSpecial     = true;
    private bool $checkCommon        = true;
    private bool $hibpEnabled        = false;

    public function minLength(int $length): static { $this->minLength = $length; return $this; }

    public function maxLength(int $length): static { $this->maxLength = $length; return $this; }

    public function requireUppercase(bool $require = true): static { $this->requireUppercase = $require; return $this; }

    public function requireLowercase(bool $require = true): static { $this->requireLowercase = $require; return $this; }

    public function requireDigit(bool $require = true): static { $this->requireDigit = $require; return $this; }

    public function requireSpecial(bool $require = true): static { $this->requireSpecial = $require; return $this; }

    public function checkCommonPasswords(bool $check = true): static { $this->checkCommon = $check; return $this; }

    /**
     * Enable HaveIBeenPwned breach check.
     *
     * Uses k-anonymity (sends only first 5 chars of SHA-1 hash) — password never leaves the process.
     * Requires an HTTP client: ext-curl or symfony/http-client.
     */
    public function hibp(bool $enabled = true): static { $this->hibpEnabled = $enabled; return $this; }

    public function toArray(): array
    {
        return [
            'min_length'        => $this->minLength,
            'max_length'        => $this->maxLength,
            'require_uppercase' => $this->requireUppercase,
            'require_lowercase' => $this->requireLowercase,
            'require_digit'     => $this->requireDigit,
            'require_special'   => $this->requireSpecial,
            'check_common'      => $this->checkCommon,
            'hibp_enabled'      => $this->hibpEnabled,
        ];
    }
}

final class EncryptionConfig
{
    private bool   $enabled      = false;
    private string $masterKeyEnv = 'ENCRYPTION_KEY';
    private string $algorithm    = 'aes-256-gcm';

    /** Enable field-level encryption. Requires ENCRYPTION_KEY in environment (32 raw bytes, base64-encoded). */
    public function enabled(bool $enabled = true): static { $this->enabled = $enabled; return $this; }

    /** Name of the environment variable holding the base64-encoded 32-byte master key. */
    public function masterKeyEnv(string $envVar): static { $this->masterKeyEnv = $envVar; return $this; }

    public function toArray(): array
    {
        return [
            'enabled'       => $this->enabled,
            'master_key_env' => $this->masterKeyEnv,
            'algorithm'     => $this->algorithm,
        ];
    }
}

final class SecretsConfig
{
    private string $driver  = EnvSecretsProvider::class;
    private string $vaultAddr   = '';
    private string $vaultToken  = '';
    private string $roleId      = '';
    private string $secretId    = '';
    private int    $cacheTtl    = 300;
    private string $awsRegion   = '';
    private string $awsPrefix   = '';

    public function driver(string $providerClass): static { $this->driver = $providerClass; return $this; }

    public function vaultAddr(string $addr): static { $this->vaultAddr = $addr; return $this; }

    /** Static token auth — use roleId/secretId for AppRole (preferred in prod). */
    public function vaultToken(string $token): static { $this->vaultToken = $token; return $this; }

    public function roleId(string $roleId): static { $this->roleId = $roleId; return $this; }

    public function secretId(string $secretId): static { $this->secretId = $secretId; return $this; }

    /** How long to cache fetched secrets (seconds). 0 = no cache. */
    public function cacheTtl(int $seconds): static { $this->cacheTtl = $seconds; return $this; }

    public function awsRegion(string $region): static { $this->awsRegion = $region; return $this; }

    public function awsPrefix(string $prefix): static { $this->awsPrefix = $prefix; return $this; }

    public function toArray(): array
    {
        return [
            'driver'      => $this->driver,
            'vault_addr'  => $this->vaultAddr,
            'vault_token' => $this->vaultToken,
            'role_id'     => $this->roleId,
            'secret_id'   => $this->secretId,
            'cache_ttl'   => $this->cacheTtl,
            'aws_region'  => $this->awsRegion,
            'aws_prefix'  => $this->awsPrefix,
        ];
    }
}

final class DataMaskingConfig
{
    private bool   $enabled         = false;
    private string $defaultStrategy = MaskPartialStrategy::class;
    private bool   $allChannels     = false;

    public function enabled(bool $enabled = true): static { $this->enabled = $enabled; return $this; }

    public function defaultStrategy(string $strategyClass): static { $this->defaultStrategy = $strategyClass; return $this; }

    /** Apply masking processor to ALL log channels, not just the security channel. */
    public function allChannels(bool $all = true): static { $this->allChannels = $all; return $this; }

    public function toArray(): array
    {
        return [
            'enabled'          => $this->enabled,
            'default_strategy' => $this->defaultStrategy,
            'all_channels'     => $this->allChannels,
        ];
    }
}
