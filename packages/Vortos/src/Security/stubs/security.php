<?php

declare(strict_types=1);

use Vortos\Security\DependencyInjection\VortosSecurityConfig;
use Vortos\Security\Masking\Strategy\MaskAllStrategy;
use Vortos\Security\Masking\Strategy\MaskPartialStrategy;
use Vortos\Security\Secrets\EnvSecretsProvider;
use Vortos\Security\Secrets\VaultSecretsProvider;

// All security features are configured here.
//
// For per-environment overrides create config/{env}/security.php.
// It is loaded after this file — any value set there wins.
//
// DEV EXAMPLE  — config/dev/security.php:
//
//   return static function (VortosSecurityConfig $config): void {
//       $config
//           ->headers()->hsts(false)->csp()->reportOnly(true)
//           ->cors()->origins(['*'])
//           ->csrf()->enabled(false)
//           ->ipFilter()->enabled(false);
//   };
//
// PROD EXAMPLE — config/prod/security.php:
//
//   return static function (VortosSecurityConfig $config): void {
//       $config
//           ->headers()
//               ->hsts(maxAge: 31536000, includeSubDomains: true, preload: true)
//               ->csp()->reportUri('/csp-report')
//           ->cors()->origins(['https://app.example.com'])
//           ->csrf()->enabled(true)->cookieSecure(true)
//           ->ipFilter()->enabled(true);
//   };

return static function (VortosSecurityConfig $config): void {

    // =========================================================================
    // HTTP Security Headers — applied to every response
    // =========================================================================
    $config->headers()
        // HSTS — forces HTTPS. Enable only in prod — dev breaks without HTTPS.
        // ->hsts(maxAge: 31536000, includeSubDomains: true, preload: false)

        // Clickjacking protection
        ->xFrameOptions('DENY')

        // Prevent MIME-type sniffing
        ->xContentTypeOptions(true)

        // Referrer leakage control
        ->referrerPolicy('strict-origin-when-cross-origin')

        // Permissions-Policy — restrict browser features. Empty array = deny all.
        // ->permissionsPolicy(['camera' => [], 'microphone' => [], 'geolocation' => []])

        // Content Security Policy
        // ->csp()
        //     ->defaultSrc("'self'")
        //     ->scriptSrc("'self'", "'nonce-{nonce}'")
        //     ->styleSrc("'self'")
        //     ->imgSrc("'self'", 'data:')
        //     ->reportUri('/csp-report')
        //     ->reportOnly(true) // start in report-only mode during rollout
    ;

    // =========================================================================
    // CORS — Cross-Origin Resource Sharing
    // =========================================================================
    $config->cors()
        // List allowed origins. '*' is insecure in prod — use exact domains.
        ->origins(['https://app.example.com'])
        ->methods(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])
        ->allowedHeaders(['Content-Type', 'Authorization', 'X-Requested-With', 'X-CSRF-Token'])
        ->credentials(false)
        ->maxAge(3600)
    ;

    // =========================================================================
    // CSRF — Double-submit cookie protection
    // =========================================================================
    $config->csrf()
        ->enabled(true)
        ->headerName('X-CSRF-Token')
        ->cookieName('csrf_token')
        // ->cookieSecure(true)       // enable in prod (HTTPS only)
        ->cookieSameSite('Strict')
    ;

    // =========================================================================
    // IP Filtering — allowlist / denylist
    // =========================================================================
    $config->ipFilter()
        ->enabled(false)             // set true in prod if you have known IP ranges
        // ->allow(['203.0.113.0/24'])  // allowlist — all other IPs denied
        // ->deny(['10.0.0.0/8'])       // denylist — specific ranges blocked
        ->trustedProxies(['127.0.0.1', '::1'])
    ;

    // =========================================================================
    // Password Policy
    // =========================================================================
    $config->passwordPolicy()
        ->minLength(12)
        ->maxLength(128)
        ->requireUppercase(true)
        ->requireLowercase(true)
        ->requireDigit(true)
        ->requireSpecial(true)
        ->checkCommonPasswords(true)
        // ->hibp(true)  // opt-in: check HaveIBeenPwned (requires curl, network access)
    ;

    // =========================================================================
    // Field-Level Encryption (opt-in)
    // =========================================================================
    // $config->encryption()
    //     ->enabled(true)
    //     // Generate: base64_encode(random_bytes(32)) — store in secrets manager
    //     ->masterKeyEnv('ENCRYPTION_KEY')
    // ;

    // =========================================================================
    // Secrets Management (opt-in drivers)
    // =========================================================================
    // Default: EnvSecretsProvider reads $_ENV — zero overhead, no dependencies.
    //
    // HashiCorp Vault (AppRole):
    // $config->secrets()
    //     ->driver(VaultSecretsProvider::class)
    //     ->vaultAddr($_ENV['VAULT_ADDR'] ?? 'https://vault:8200')
    //     ->roleId($_ENV['VAULT_ROLE_ID'] ?? '')
    //     ->secretId('env:VAULT_SECRET_ID')  // resolved at runtime from $_ENV
    //     ->cacheTtl(300)                     // cache secrets for 5 minutes
    // ;

    // =========================================================================
    // Data Masking — PII redaction in logs (opt-in)
    // =========================================================================
    // $config->dataMasking()
    //     ->enabled(true)
    //     ->defaultStrategy(MaskPartialStrategy::class)  // or MaskAllStrategy
    //     ->allChannels(false)  // true = mask PII in ALL log channels, not just security
    // ;
};
