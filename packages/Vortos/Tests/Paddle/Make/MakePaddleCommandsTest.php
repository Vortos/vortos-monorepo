<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\Make;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Foundation\Module\ModulePathResolver;
use Vortos\Make\Engine\GeneratorEngine;
use Vortos\Make\Scanner\StubScanner;
use Vortos\Paddle\Make\MakePaddleSubscriptionServiceCommand;
use Vortos\Paddle\Make\MakePaddleWebhookHandlerCommand;

final class MakePaddleCommandsTest extends TestCase
{
    private string $projectDir;
    private GeneratorEngine $engine;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/vortos-paddle-make-test-' . uniqid();
        mkdir($this->projectDir . '/src', 0755, true);

        $resolver     = new ModulePathResolver($this->findProjectRoot());
        $scanner      = new StubScanner($resolver, $this->projectDir);
        $this->engine = new GeneratorEngine($scanner, $this->projectDir);
    }

    private function findProjectRoot(): string
    {
        $dir = __DIR__;
        while ($dir !== \DIRECTORY_SEPARATOR) {
            if (file_exists($dir . '/vendor/composer/installed.json')) {
                return $dir;
            }
            $dir = dirname($dir);
        }
        throw new \RuntimeException('Cannot locate project root (no vendor/composer/installed.json found)');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectDir);
    }

    // ── make:paddle:webhook-handler ───────────────────────────────────────────

    public function test_webhook_handler_generates_file_in_correct_path(): void
    {
        $tester = new CommandTester(new MakePaddleWebhookHandlerCommand($this->engine));
        $tester->execute(['name' => 'SubscriptionCanceled', '--context' => 'Billing']);

        $this->assertFileExists($this->src('Billing/Infrastructure/Paddle/SubscriptionCanceledHandler.php'));
    }

    public function test_webhook_handler_namespace_is_correct(): void
    {
        $tester = new CommandTester(new MakePaddleWebhookHandlerCommand($this->engine));
        $tester->execute(['name' => 'SubscriptionCanceled', '--context' => 'Billing']);

        $content = file_get_contents($this->src('Billing/Infrastructure/Paddle/SubscriptionCanceledHandler.php'));
        $this->assertStringContainsString('namespace App\Billing\Infrastructure\Paddle;', $content);
    }

    public function test_webhook_handler_class_name_has_handler_suffix(): void
    {
        $tester = new CommandTester(new MakePaddleWebhookHandlerCommand($this->engine));
        $tester->execute(['name' => 'SubscriptionCanceled', '--context' => 'Billing']);

        $content = file_get_contents($this->src('Billing/Infrastructure/Paddle/SubscriptionCanceledHandler.php'));
        $this->assertStringContainsString('final class SubscriptionCanceledHandler', $content);
    }

    public function test_webhook_handler_uses_event_type(): void
    {
        $tester = new CommandTester(new MakePaddleWebhookHandlerCommand($this->engine));
        $tester->execute(['name' => 'SubscriptionCanceled', '--context' => 'Billing']);

        $content = file_get_contents($this->src('Billing/Infrastructure/Paddle/SubscriptionCanceledHandler.php'));
        $this->assertStringContainsString('SubscriptionCanceledEvent', $content);
    }

    public function test_webhook_handler_imports_event_from_paddle_namespace(): void
    {
        $tester = new CommandTester(new MakePaddleWebhookHandlerCommand($this->engine));
        $tester->execute(['name' => 'SubscriptionCanceled', '--context' => 'Billing']);

        $content = file_get_contents($this->src('Billing/Infrastructure/Paddle/SubscriptionCanceledHandler.php'));
        $this->assertStringContainsString('use Vortos\Paddle\Webhook\Event\SubscriptionCanceledEvent;', $content);
    }

    public function test_webhook_handler_requires_context(): void
    {
        $tester = new CommandTester(new MakePaddleWebhookHandlerCommand($this->engine));
        $tester->execute(['name' => 'SubscriptionCanceled']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('--context', $tester->getDisplay());
    }

    public function test_webhook_handler_works_with_different_contexts(): void
    {
        $tester = new CommandTester(new MakePaddleWebhookHandlerCommand($this->engine));
        $tester->execute(['name' => 'TransactionCompleted', '--context' => 'Payments']);

        $this->assertFileExists($this->src('Payments/Infrastructure/Paddle/TransactionCompletedHandler.php'));
        $content = file_get_contents($this->src('Payments/Infrastructure/Paddle/TransactionCompletedHandler.php'));
        $this->assertStringContainsString('namespace App\Payments\Infrastructure\Paddle;', $content);
        $this->assertStringContainsString('TransactionCompletedEvent', $content);
    }

    // ── make:paddle:subscription-service ─────────────────────────────────────

    public function test_subscription_service_generates_file_in_correct_path(): void
    {
        $tester = new CommandTester(new MakePaddleSubscriptionServiceCommand($this->engine));
        $tester->execute(['name' => 'BillingSubscriptionService', '--context' => 'Billing']);

        $this->assertFileExists($this->src('Billing/Infrastructure/Paddle/BillingSubscriptionService.php'));
    }

    public function test_subscription_service_namespace_is_correct(): void
    {
        $tester = new CommandTester(new MakePaddleSubscriptionServiceCommand($this->engine));
        $tester->execute(['name' => 'BillingSubscriptionService', '--context' => 'Billing']);

        $content = file_get_contents($this->src('Billing/Infrastructure/Paddle/BillingSubscriptionService.php'));
        $this->assertStringContainsString('namespace App\Billing\Infrastructure\Paddle;', $content);
    }

    public function test_subscription_service_class_name_matches_argument(): void
    {
        $tester = new CommandTester(new MakePaddleSubscriptionServiceCommand($this->engine));
        $tester->execute(['name' => 'BillingSubscriptionService', '--context' => 'Billing']);

        $content = file_get_contents($this->src('Billing/Infrastructure/Paddle/BillingSubscriptionService.php'));
        $this->assertStringContainsString('final class BillingSubscriptionService', $content);
    }

    public function test_subscription_service_implements_standalone_interface(): void
    {
        $tester = new CommandTester(new MakePaddleSubscriptionServiceCommand($this->engine));
        $tester->execute(['name' => 'BillingSubscriptionService', '--context' => 'Billing']);

        $content = file_get_contents($this->src('Billing/Infrastructure/Paddle/BillingSubscriptionService.php'));
        $this->assertStringContainsString('StandaloneSubscriptionServiceInterface', $content);
    }

    public function test_subscription_service_contains_all_interface_methods(): void
    {
        $tester = new CommandTester(new MakePaddleSubscriptionServiceCommand($this->engine));
        $tester->execute(['name' => 'BillingSubscriptionService', '--context' => 'Billing']);

        $content = file_get_contents($this->src('Billing/Infrastructure/Paddle/BillingSubscriptionService.php'));
        $this->assertStringContainsString('public function get(', $content);
        $this->assertStringContainsString('public function update(', $content);
        $this->assertStringContainsString('public function pause(', $content);
        $this->assertStringContainsString('public function resume(', $content);
        $this->assertStringContainsString('public function cancel(', $content);
        $this->assertStringContainsString('public function activate(', $content);
        $this->assertStringContainsString('public function previewUpdate(', $content);
        $this->assertStringContainsString('public function list(', $content);
    }

    public function test_subscription_service_requires_context(): void
    {
        $tester = new CommandTester(new MakePaddleSubscriptionServiceCommand($this->engine));
        $tester->execute(['name' => 'BillingSubscriptionService']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('--context', $tester->getDisplay());
    }

    public function test_subscription_service_works_with_different_contexts(): void
    {
        $tester = new CommandTester(new MakePaddleSubscriptionServiceCommand($this->engine));
        $tester->execute(['name' => 'PaymentsSubscriptionService', '--context' => 'Payments']);

        $this->assertFileExists($this->src('Payments/Infrastructure/Paddle/PaymentsSubscriptionService.php'));
        $content = file_get_contents($this->src('Payments/Infrastructure/Paddle/PaymentsSubscriptionService.php'));
        $this->assertStringContainsString('namespace App\Payments\Infrastructure\Paddle;', $content);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function src(string $path): string
    {
        return $this->projectDir . '/src/' . $path;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
