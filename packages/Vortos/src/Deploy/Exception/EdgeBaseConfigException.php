<?php

declare(strict_types=1);

namespace Vortos\Deploy\Exception;

/**
 * Fail-closed errors from the edge adapt-merge pipeline.
 *
 * Every message is operator-facing and SECRET-FREE: it names the domain, the count, and the
 * remediation, never the config body (which routinely carries basicauth hashes / forward_auth
 * tokens). Callers surface these verbatim to the operator at preflight or cutover.
 */
final class EdgeBaseConfigException extends DeployException
{
    public static function unreadable(string $path, string $reason): self
    {
        return new self(sprintf('edge base config: cannot read "%s": %s', $path, $reason));
    }

    public static function pathEscape(string $path): self
    {
        return new self(sprintf(
            'edge base config: "%s" resolves outside the project root (symlink or traversal); refusing to read it.',
            $path,
        ));
    }

    public static function tooLarge(string $path, int $bytes, int $limit): self
    {
        return new self(sprintf(
            'edge base config: "%s" is %d bytes, over the %d-byte limit; refusing to adapt (DoS guard).',
            $path,
            $bytes,
            $limit,
        ));
    }

    public static function adaptFailed(string $redactedReason): self
    {
        return new self(sprintf('edge base config: Caddyfile did not adapt to JSON: %s', $redactedReason));
    }

    public static function adaptOutputTooLarge(int $bytes, int $limit): self
    {
        return new self(sprintf(
            'edge base config: adapted JSON is %d bytes, over the %d-byte limit; refusing to load (DoS guard).',
            $bytes,
            $limit,
        ));
    }

    public static function ambiguousAppProxy(string $domain, int $count): self
    {
        return new self(sprintf(
            'edge base config: found %d reverse_proxy handlers targeting app-<color> for "%s"; the framework '
            . 'needs exactly one to own the blue/green upstream. Keep a single app proxy, or move the other '
            . 'onto its own path/host matcher.',
            $count,
            $domain,
        ));
    }

    public static function noSiteBlockForDomain(string $domain): self
    {
        return new self(sprintf(
            'edge base config: no site block matches "%s"; the framework cannot place the app upstream. Add a '
            . 'site block for the domain (e.g. "%s { reverse_proxy app-blue:8080 }") or unset edge.app_domain.',
            $domain,
            $domain,
        ));
    }

    public static function adminNotLoopback(string $requestedListen): self
    {
        return new self(sprintf(
            'edge base config: the admin block binds "%s"; the Caddy admin API must stay on loopback and is '
            . 'never exposed. Remove the admin listen override from the Caddyfile so the framework can pin it.',
            $requestedListen,
        ));
    }

    public static function privilegedListener(string $port): self
    {
        return new self(sprintf(
            'edge base config: an HTTP server listens on port %s; the edge may only bind 80/443. Remove the '
            . 'extra listener.',
            $port,
        ));
    }

    public static function tlsDropped(string $domain): self
    {
        return new self(sprintf(
            'edge base config: the merged config does not retain tls.automation for "%s"; a reload would clobber '
            . 'its certificate. This is a framework guard failure, not an operator error — do not deploy.',
            $domain,
        ));
    }

    public static function unexpectedMutation(string $detail): self
    {
        return new self(sprintf(
            'edge base config: the merged config changed more than the app upstream (%s); refusing to load. This '
            . 'is a framework guard failure — do not deploy.',
            $detail,
        ));
    }

    public static function invalidJson(string $path, string $reason): self
    {
        return new self(sprintf('edge base config: "%s" is not valid JSON: %s', $path, $reason));
    }
}
