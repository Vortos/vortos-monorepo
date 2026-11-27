<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\Kafka\Runtime;

use InvalidArgumentException;

/**
 * The one place a Kafka client's connection settings are worked out: which brokers, and how it signs in.
 *
 * Every rdkafka client the framework builds (producer, consumer, health check, `vortos:kafka:tail`) takes its
 * connection from here, so a broker that requires authentication is reached the same way by all of them.
 * They used to read the DSN and the security block each in their own way, and had drifted: the consumer
 * refused SASL without TLS while the producer allowed it, and the health check ignored security entirely.
 * KafkaClientConfigSingleSourceTest fails the build if anything else constructs an \RdKafka\Conf.
 *
 * The DSN carries the sign-in, as the Redis and database DSNs do, so every transport on the same broker
 * inherits it from one environment variable:
 *
 *     kafka://broker1:9092,broker2:9092
 *     kafka://user:password@kafka:9094                          SCRAM-SHA-512 (the default mechanism)
 *     kafka://user:password@kafka:9094?sasl_mechanism=SCRAM-SHA-256
 *
 * The user and password are URL-decoded, so a generated password may hold any character. A transport may
 * instead declare ->security(SaslConfig), as before; declaring both is refused rather than guessed between.
 *
 * Without TLS: SCRAM is allowed, PLAIN is refused. SCRAM (RFC 5802) proves the password by challenge and
 * response and never sends it, so on a private network it authenticates without exposing the secret; the
 * traffic itself is not encrypted, so add ->security(SslConfig) when the network is not private. PLAIN sends
 * the password as it is, and is accepted only over TLS.
 *
 * No message produced here ever contains the password: errors quote {@see self::redact()}.
 */
final class KafkaClientConfig
{
    public const DEFAULT_MECHANISM = 'SCRAM-SHA-512';
    private const MECHANISMS = ['SCRAM-SHA-512', 'SCRAM-SHA-256', 'PLAIN'];
    private const QUERY_KEYS = ['sasl_mechanism'];

    private function __construct() {}

    /**
     * The rdkafka settings that reach a transport's brokers and sign in as it declares.
     *
     * @param array{dsn?: string, security?: array{sasl?: array<string, mixed>, ssl?: array<string, mixed>}} $transport
     * @return array<string, string>
     */
    public static function settings(array $transport, string $label): array
    {
        $dsn = self::parse((string) ($transport['dsn'] ?? ''), $label);
        $sasl = $transport['security']['sasl'] ?? [];
        $ssl = $transport['security']['ssl'] ?? [];

        if ($dsn['username'] !== null && $sasl !== []) {
            throw new InvalidArgumentException(sprintf(
                "Kafka transport '%s' signs in both through its DSN and through ->security(); declare one.",
                $label,
            ));
        }

        $settings = ['metadata.broker.list' => $dsn['brokers']];

        $mechanism = null;
        if ($dsn['username'] !== null) {
            $mechanism = $dsn['mechanism'];
            $settings['sasl.username'] = $dsn['username'];
            $settings['sasl.password'] = (string) $dsn['password'];
        } elseif ($sasl !== []) {
            $mechanism = (string) ($sasl['mechanism'] ?? '');
            $username = (string) ($sasl['username'] ?? '');
            $password = (string) ($sasl['password'] ?? '');
            if (!in_array($mechanism, self::MECHANISMS, true)) {
                throw new InvalidArgumentException(sprintf("Kafka transport '%s' declares an unknown SASL mechanism '%s'.", $label, $mechanism));
            }
            if ($username === '' || $password === '') {
                throw new InvalidArgumentException(sprintf("SASL username and password must not be empty for Kafka transport '%s'.", $label));
            }
            $settings['sasl.username'] = $username;
            $settings['sasl.password'] = $password;
        }

        if ($ssl !== []) {
            foreach (['ca_location' => 'ssl.ca.location', 'certificate_location' => 'ssl.certificate.location', 'key_location' => 'ssl.key.location'] as $key => $setting) {
                if (!isset($ssl[$key])) {
                    continue;
                }
                $path = (string) $ssl[$key];
                if (!is_readable($path)) {
                    throw new InvalidArgumentException(sprintf("Kafka transport '%s': SSL file is not readable: %s", $label, $path));
                }
                $settings[$setting] = $path;
            }
            if (isset($ssl['key_password'])) {
                $settings['ssl.key.password'] = (string) $ssl['key_password'];
            }
            if (isset($ssl['verify_peer'])) {
                $settings['enable.ssl.certificate.verification'] = $ssl['verify_peer'] ? 'true' : 'false';
            }
        }

        if ($mechanism !== null) {
            if ($mechanism === 'PLAIN' && $ssl === []) {
                throw new InvalidArgumentException(sprintf(
                    "Kafka transport '%s' uses SASL PLAIN without TLS, which would send the password in plaintext. Use SCRAM-SHA-512, or configure SSL.",
                    $label,
                ));
            }
            $settings['sasl.mechanisms'] = $mechanism;
            $settings['security.protocol'] = $ssl === [] ? 'SASL_PLAINTEXT' : 'SASL_SSL';
        } elseif ($ssl !== []) {
            $settings['security.protocol'] = 'SSL';
        }

        return $settings;
    }

    /**
     * An \RdKafka\Conf with the transport's connection settings, plus the caller's own (timeouts, group id).
     * The caller's settings may not override how the client connects or signs in.
     *
     * @param array<string, mixed> $transport
     * @param array<string, string> $extra
     */
    public static function conf(array $transport, string $label, array $extra = []): \RdKafka\Conf
    {
        $settings = self::settings($transport, $label);
        foreach (array_keys($extra) as $key) {
            if (isset($settings[$key]) || str_starts_with($key, 'sasl.') || str_starts_with($key, 'ssl.') || $key === 'security.protocol') {
                throw new InvalidArgumentException(sprintf("Kafka transport '%s': '%s' is set by its DSN and security, not by the caller.", $label, $key));
            }
        }

        $conf = new \RdKafka\Conf();
        foreach ($settings + $extra as $key => $value) {
            $conf->set($key, $value);
        }

        return $conf;
    }

    /** The broker list without any credentials: what a transport may be grouped or displayed by. */
    public static function brokers(string $dsn, string $label = 'kafka'): string
    {
        return self::parse($dsn, $label)['brokers'];
    }

    /** The text with any DSN password in it masked, for messages. Never throws. */
    public static function redact(string $dsn): string
    {
        // Anywhere in the text (an error message may quote a DSN), up to the last '@' of the token, so a
        // password holding a raw '@' is masked whole.
        return (string) preg_replace('#([a-z][a-z0-9+.-]*://[^:@/\s]*):[^\s/]*@#i', '$1:***@', $dsn);
    }

    /**
     * @return array{brokers: string, username: ?string, password: ?string, mechanism: string}
     */
    private static function parse(string $dsn, string $label): array
    {
        if (!str_starts_with($dsn, 'kafka://')) {
            throw new InvalidArgumentException(sprintf("Kafka transport '%s' needs a kafka:// DSN, got '%s'.", $label, self::redact($dsn)));
        }

        $rest = substr($dsn, strlen('kafka://'));
        $query = '';
        if (($q = strpos($rest, '?')) !== false) {
            $query = substr($rest, $q + 1);
            $rest = substr($rest, 0, $q);
        }

        $rest = rtrim($rest, '/');
        $username = null;
        $password = null;
        if (($at = strrpos($rest, '@')) !== false) {
            $userinfo = substr($rest, 0, $at);
            $rest = substr($rest, $at + 1);
            [$user, $pass] = array_pad(explode(':', $userinfo, 2), 2, null);
            $username = rawurldecode((string) $user);
            $password = $pass === null ? '' : rawurldecode($pass);
            if ($username === '' || $password === '') {
                throw new InvalidArgumentException(sprintf("Kafka transport '%s': a DSN that signs in needs both a user and a password (%s).", $label, self::redact($dsn)));
            }
        }

        $brokers = array_map('trim', explode(',', $rest));
        foreach ($brokers as $broker) {
            if (preg_match('/^[A-Za-z0-9._\-]+:\d{1,5}$|^\[[0-9A-Fa-f:.]+\]:\d{1,5}$/', $broker) !== 1) {
                throw new InvalidArgumentException(sprintf("Kafka transport '%s': '%s' is not a host:port broker (%s).", $label, $broker, self::redact($dsn)));
            }
        }

        parse_str($query, $params);
        foreach (array_keys($params) as $key) {
            if (!in_array($key, self::QUERY_KEYS, true)) {
                throw new InvalidArgumentException(sprintf("Kafka transport '%s': unknown DSN option '%s' (known: %s).", $label, $key, implode(', ', self::QUERY_KEYS)));
            }
        }
        $mechanism = isset($params['sasl_mechanism']) ? (string) $params['sasl_mechanism'] : self::DEFAULT_MECHANISM;
        if (!in_array($mechanism, self::MECHANISMS, true)) {
            throw new InvalidArgumentException(sprintf("Kafka transport '%s': unknown sasl_mechanism '%s' (known: %s).", $label, $mechanism, implode(', ', self::MECHANISMS)));
        }
        if ($username === null && isset($params['sasl_mechanism'])) {
            throw new InvalidArgumentException(sprintf("Kafka transport '%s': sasl_mechanism without a user and password in the DSN.", $label));
        }

        return ['brokers' => implode(',', $brokers), 'username' => $username, 'password' => $password, 'mechanism' => $mechanism];
    }
}
