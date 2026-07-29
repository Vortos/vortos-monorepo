<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\Csrf;

use PHPUnit\Framework\TestCase;
use Vortos\Security\Csrf\Attribute\SkipCsrf;

/**
 * Every webhook receiver must be exempt from CSRF.
 *
 * A webhook sender is not a browser. It holds no cookie to double-submit and cannot
 * be made to send one, so an application with CSRF enabled rejects every delivery
 * before the payload's own signature is ever checked. The failure is silent from
 * both ends: our side logs nothing because the request never reaches a handler, and
 * the provider sees only a 403 it will stop retrying.
 *
 * That is not hypothetical — it took down every Paddle webhook in a production
 * deployment, and was found only because a plan change failed to reach the database.
 * The signature, source-IP and replay checks these controllers already run are
 * strictly stronger than an ambient cookie, so skipping CSRF costs nothing.
 *
 * This test enumerates the receivers rather than trusting review to catch the next
 * one added.
 */
final class WebhookReceiversSkipCsrfTest extends TestCase
{
    /**
     * Controllers that receive third-party webhooks.
     *
     * @return array<string, array{class-string}>
     */
    public static function webhookControllers(): array
    {
        return [
            'paddle'  => [\Vortos\Paddle\Webhook\PaddleWebhookController::class],
            'aws-ses' => [\Vortos\AwsSes\Webhook\SnsWebhookController::class],
        ];
    }

    /**
     * @param class-string $controller
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('webhookControllers')]
    public function test_a_webhook_receiver_is_exempt_from_csrf(string $controller): void
    {
        $reflection = new \ReflectionClass($controller);

        $onClass = $reflection->getAttributes(SkipCsrf::class) !== [];

        $onMethod = false;
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(SkipCsrf::class) !== []) {
                $onMethod = true;
                break;
            }
        }

        self::assertTrue(
            $onClass || $onMethod,
            $controller . ' receives third-party webhooks and must carry #[SkipCsrf]; '
            . 'without it CSRF rejects every delivery before its signature is checked.',
        );
    }
}
