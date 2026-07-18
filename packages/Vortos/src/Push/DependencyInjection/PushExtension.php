<?php

declare(strict_types=1);

namespace Vortos\Push\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Push\Command\GenerateVapidKeysCommand;
use Vortos\Push\Config\VapidKeys;
use Vortos\Push\Contract\WebPushSenderInterface;
use Vortos\Push\Crypto\WebPushEncryptor;
use Vortos\Push\Driver\CurlWebPushSender;
use Vortos\Push\Vapid\VapidHeaderFactory;

/**
 * Wires the Web Push services. VAPID keys are read from the environment with
 * empty defaults, so an app without keys configured degrades cleanly
 * (isConfigured() === false) rather than erroring at container build.
 *
 *   WebPushEncryptor        — RFC 8291 aes128gcm
 *   VapidHeaderFactory      — ES256 VAPID authorization
 *   CurlWebPushSender       — the default driver
 *   WebPushSenderInterface  — public alias to the driver
 *   GenerateVapidKeysCommand
 */
final class PushExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_push';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        // Runtime env with defaults — the compiled container stays env-agnostic.
        $container->setParameter('env(VAPID_PUBLIC_KEY)', '');
        $container->setParameter('env(VAPID_PRIVATE_KEY)', '');
        $container->setParameter('env(VAPID_SUBJECT)', '');

        $container->register(VapidKeys::class, VapidKeys::class)
            ->setArgument('$publicKey', '%env(VAPID_PUBLIC_KEY)%')
            ->setArgument('$privateKeyPem', '%env(VAPID_PRIVATE_KEY)%')
            ->setArgument('$subject', '%env(VAPID_SUBJECT)%')
            ->setPublic(false);

        $container->register(WebPushEncryptor::class, WebPushEncryptor::class)
            ->setPublic(false);

        $container->register(VapidHeaderFactory::class, VapidHeaderFactory::class)
            ->setArgument('$keys', new Reference(VapidKeys::class))
            ->setPublic(false);

        $container->register(CurlWebPushSender::class, CurlWebPushSender::class)
            ->setArgument('$encryptor', new Reference(WebPushEncryptor::class))
            ->setArgument('$vapid', new Reference(VapidHeaderFactory::class))
            ->setArgument('$keys', new Reference(VapidKeys::class))
            ->setPublic(true);

        $container->setAlias(WebPushSenderInterface::class, CurlWebPushSender::class)
            ->setPublic(true);

        $container->register(GenerateVapidKeysCommand::class, GenerateVapidKeysCommand::class)
            ->setPublic(true)
            ->addTag('console.command');
    }
}
