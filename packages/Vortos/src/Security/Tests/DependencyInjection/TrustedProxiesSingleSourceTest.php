<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Vortos\Security\DependencyInjection\SecurityExtension;
use Vortos\Security\DependencyInjection\VortosSecurityConfig;
use Vortos\Security\Doctor\TrustedProxyDoctorCheck;
use Vortos\Security\IpFilter\IpResolver;

/**
 * The IP-scoped rate limits and the IP filter resolve the client through this module's IpResolver.
 * It once read its own trusted-proxy list (loopback by default) instead of vortos.trusted_proxies,
 * so a correctly configured proxy still left every rate limit keyed on the proxy's address.
 */
final class TrustedProxiesSingleSourceTest extends TestCase
{
    public function test_the_ip_resolver_and_doctor_read_vortos_trusted_proxies(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir() . '/vortos-no-such-project-' . bin2hex(random_bytes(4)));
        $container->setParameter('kernel.env', 'test');
        $container->setParameter('vortos.trusted_proxies', ['172.22.0.0/16']);
        $container->setParameter('vortos.has_ip_rate_limits', true);

        (new SecurityExtension())->load([], $container);

        $resolver = $container->getDefinition(IpResolver::class);
        self::assertSame('%vortos.trusted_proxies%', $resolver->getArgument('$trustedProxies'));
        self::assertSame('%vortos.trusted_proxies%', $container->getDefinition(TrustedProxyDoctorCheck::class)->getArgument(0));

        // Resolved, the edge's forwarded client is believed and a peer on any other network is not.
        $proxies = $container->getParameterBag()->resolveValue($resolver->getArgument('$trustedProxies'));
        $ipResolver = new IpResolver($proxies);
        self::assertSame('203.0.113.7', $ipResolver->resolve(Request::create('/', server: ['REMOTE_ADDR' => '172.22.0.4', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'])));
        self::assertSame('172.18.0.5', $ipResolver->resolve(Request::create('/', server: ['REMOTE_ADDR' => '172.18.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'])));
    }

    public function test_there_is_no_second_list_to_configure(): void
    {
        self::assertFalse(method_exists(\Vortos\Security\DependencyInjection\IpFilterConfig::class, 'trustedProxies'));
        self::assertArrayNotHasKey('trusted_proxies', (new VortosSecurityConfig())->toArray()['ip_filter']);
    }
}
