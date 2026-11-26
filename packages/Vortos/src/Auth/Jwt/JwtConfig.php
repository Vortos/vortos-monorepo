<?php

declare(strict_types=1);

namespace Vortos\Auth\Jwt;

use Vortos\Auth\Jwt\Key\Keyring;

/**
 * Immutable JWT configuration.
 * Built by AuthExtension from VortosAuthConfig.
 *
 * Signing material lives in the {@see Keyring}, which carries one or more
 * kid-addressed keys for zero-downtime rotation. See {@see JwtService} for how
 * the active key signs and the whole ring verifies.
 */
final readonly class JwtConfig
{
    public function __construct(
        /**
         * The signing keyring. Tokens are signed by the ring's Active key and
         * verified against every key in the ring (by `kid`).
         */
        public Keyring $keyring,

        /**
         * Access token TTL in seconds. Default: 900 (15 minutes).
         */
        public int $accessTokenTtl = 900,

        /**
         * Refresh token TTL in seconds. Default: 604800 (7 days).
         */
        public int $refreshTokenTtl = 604800,

        /**
         * Token issuer — included in 'iss' claim. Must be explicitly set.
         */
        public string $issuer = 'vortos',

        /**
         * Default token audience — written to the 'aud' claim by {@see JwtService::issue()}
         * when the caller does not pass an explicit one. Prevents cross-service token
         * confusion when services share a keyring.
         */
        public string $audience = 'vortos',

        /**
         * The audiences this service will ACCEPT on validation, beyond {@see $audience}.
         *
         * A single API that serves several first-party clients (e.g. a customer SPA and an
         * admin console) mints a distinct audience per client — see
         * {@see JwtService::issue()}'s `$audience` parameter — so that a token minted for one
         * client is structurally rejected on the other's routes. All of those audiences must
         * be listed here, or validation of a legitimately-issued token fails.
         *
         * The default audience is always accepted whether or not it appears here, so the
         * common single-audience deployment needs no configuration. Empty means "accept only
         * the default audience", which is the historical behaviour.
         *
         * @var list<string>
         */
        public array $additionalAudiences = [],

        /**
         * The identity attribute every session is bound to, or null for no binding.
         *
         * When set, {@see JwtService::issue()} copies the identity's value of this attribute into
         * the refresh token (and keeps it out of the access token), and {@see JwtService::refresh()}
         * refuses a refresh token whose recorded value differs from the value the freshly loaded
         * identity carries now. The application points it at something that changes whenever the
         * account's way of signing in changes (a credential stamp rotated on every password or
         * second-factor change), so such a change ends every session opened before it, at that
         * session's next refresh, whatever happened or failed to happen in the token store.
         *
         * Industry precedent: ASP.NET Core Identity's security stamp, Django's session auth hash.
         */
        public ?string $sessionBindingAttribute = null,
    ) {
        if ($sessionBindingAttribute !== null && trim($sessionBindingAttribute) === '') {
            throw new \InvalidArgumentException('The session binding attribute must be a non-empty attribute name, or null.');
        }
    }

    /**
     * Every audience this service accepts on validation: the default plus any additional ones,
     * de-duplicated. Issuance still defaults to {@see $audience}; this governs acceptance only.
     *
     * @return list<string>
     */
    public function acceptedAudiences(): array
    {
        return array_values(array_unique([$this->audience, ...$this->additionalAudiences]));
    }

    /**
     * Whether a token's `aud` claim is one this service accepts.
     */
    public function acceptsAudience(mixed $audience): bool
    {
        return is_string($audience) && in_array($audience, $this->acceptedAudiences(), true);
    }

    /**
     * Convenience constructor for the common single-secret HS256 case (tests, single-service apps).
     *
     * @param list<string> $additionalAudiences
     */
    public static function fromSecret(
        string $secret,
        int $accessTokenTtl = 900,
        int $refreshTokenTtl = 604800,
        string $issuer = 'vortos',
        string $audience = 'vortos',
        array $additionalAudiences = [],
        ?string $sessionBindingAttribute = null,
    ): self {
        return new self(
            Keyring::fromSecret($secret),
            $accessTokenTtl,
            $refreshTokenTtl,
            $issuer,
            $audience,
            $additionalAudiences,
            $sessionBindingAttribute,
        );
    }
}
