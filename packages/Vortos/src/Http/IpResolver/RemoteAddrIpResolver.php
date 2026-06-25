<?php

declare(strict_types=1);

namespace Vortos\Http\IpResolver;

use Symfony\Component\HttpFoundation\Request;
use Vortos\Http\Contract\IpResolverInterface;

/**
 * Returns REMOTE_ADDR without any proxy awareness.
 *
 * Registered as the default IpResolverInterface in HttpExtension.
 * Security's IpResolver overrides this with proxy-aware resolution.
 */
final class RemoteAddrIpResolver implements IpResolverInterface
{
    public function resolve(Request $request): string
    {
        return $request->server->get('REMOTE_ADDR', '127.0.0.1');
    }
}
