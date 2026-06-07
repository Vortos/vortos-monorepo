<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Tests\Command\Make;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Foundation\Module\ModulePathResolver;
use Vortos\Make\Engine\GeneratorEngine;
use Vortos\Make\Scanner\StubScanner;
use Vortos\AwsSes\Command\Make\MakeBounceHandlerCommand;
use Vortos\AwsSes\Command\Make\MakeComplaintHandlerCommand;
use Vortos\AwsSes\Command\Make\MakeSesEmailMiddlewareCommand;

final class MakeSesCommandsTest extends TestCase
{
    private string $projectDir;
    private GeneratorEngine $engine;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/vortos-aws-ses-make-test-' . uniqid();
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
        throw new \RuntimeException('Cannot locate project root');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectDir);
    }

    private function src(string $relative): string
    {
        return $this->projectDir . '/src/' . $relative;
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

    // ── email-middleware ────────────────────────────────────────────────────

    public function test_email_middleware_requires_context(): void
    {
        $tester = new CommandTester(new MakeSesEmailMiddlewareCommand($this->engine));
        $tester->execute(['name' => 'Log']);
        $this->assertSame(1, $tester->getStatusCode());
    }

    public function test_email_middleware_creates_file(): void
    {
        $tester = new CommandTester(new MakeSesEmailMiddlewareCommand($this->engine));
        $tester->execute(['name' => 'Logging', '--context' => 'Notification']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileExists($this->src('Notification/Infrastructure/Email/LoggingMiddleware.php'));
    }

    public function test_email_middleware_contains_correct_class_name(): void
    {
        $tester = new CommandTester(new MakeSesEmailMiddlewareCommand($this->engine));
        $tester->execute(['name' => 'Ratelimit', '--context' => 'Billing']);

        $content = file_get_contents($this->src('Billing/Infrastructure/Email/RatelimitMiddleware.php'));
        $this->assertStringContainsString('class RatelimitMiddleware', $content);
        $this->assertStringContainsString('namespace App\\Billing', $content);
    }

    public function test_email_middleware_injects_priority(): void
    {
        $tester = new CommandTester(new MakeSesEmailMiddlewareCommand($this->engine));
        $tester->execute(['name' => 'Custom', '--context' => 'Billing', '--priority' => '750']);

        $content = file_get_contents($this->src('Billing/Infrastructure/Email/CustomMiddleware.php'));
        $this->assertStringContainsString('priority: 750', $content);
    }

    public function test_email_middleware_default_priority_is_100(): void
    {
        $tester = new CommandTester(new MakeSesEmailMiddlewareCommand($this->engine));
        $tester->execute(['name' => 'Custom', '--context' => 'Billing']);

        $content = file_get_contents($this->src('Billing/Infrastructure/Email/CustomMiddleware.php'));
        $this->assertStringContainsString('priority: 100', $content);
    }

    public function test_email_middleware_implements_interface(): void
    {
        $tester = new CommandTester(new MakeSesEmailMiddlewareCommand($this->engine));
        $tester->execute(['name' => 'Custom', '--context' => 'Billing']);

        $content = file_get_contents($this->src('Billing/Infrastructure/Email/CustomMiddleware.php'));
        $this->assertStringContainsString('EmailMiddlewareInterface', $content);
    }

    // ── bounce-handler ──────────────────────────────────────────────────────

    public function test_bounce_handler_requires_context(): void
    {
        $tester = new CommandTester(new MakeBounceHandlerCommand($this->engine));
        $tester->execute(['name' => 'NotifyAdmin']);
        $this->assertSame(1, $tester->getStatusCode());
    }

    public function test_bounce_handler_creates_file(): void
    {
        $tester = new CommandTester(new MakeBounceHandlerCommand($this->engine));
        $tester->execute(['name' => 'NotifyAdmin', '--context' => 'Notification']);

        $this->assertFileExists($this->src('Notification/Infrastructure/Email/NotifyAdminBounceHandler.php'));
    }

    public function test_bounce_handler_implements_interface(): void
    {
        $tester = new CommandTester(new MakeBounceHandlerCommand($this->engine));
        $tester->execute(['name' => 'Notify', '--context' => 'Notification']);

        $content = file_get_contents($this->src('Notification/Infrastructure/Email/NotifyBounceHandler.php'));
        $this->assertStringContainsString('BounceHandlerInterface', $content);
        $this->assertStringContainsString('BounceNotification', $content);
    }

    public function test_bounce_handler_contains_correct_class_name(): void
    {
        $tester = new CommandTester(new MakeBounceHandlerCommand($this->engine));
        $tester->execute(['name' => 'Alert', '--context' => 'Ops']);

        $content = file_get_contents($this->src('Ops/Infrastructure/Email/AlertBounceHandler.php'));
        $this->assertStringContainsString('class AlertBounceHandler', $content);
        $this->assertStringContainsString('namespace App\\Ops', $content);
    }

    // ── complaint-handler ───────────────────────────────────────────────────

    public function test_complaint_handler_requires_context(): void
    {
        $tester = new CommandTester(new MakeComplaintHandlerCommand($this->engine));
        $tester->execute(['name' => 'Unsubscribe']);
        $this->assertSame(1, $tester->getStatusCode());
    }

    public function test_complaint_handler_creates_file(): void
    {
        $tester = new CommandTester(new MakeComplaintHandlerCommand($this->engine));
        $tester->execute(['name' => 'Unsubscribe', '--context' => 'Marketing']);

        $this->assertFileExists($this->src('Marketing/Infrastructure/Email/UnsubscribeComplaintHandler.php'));
    }

    public function test_complaint_handler_implements_interface(): void
    {
        $tester = new CommandTester(new MakeComplaintHandlerCommand($this->engine));
        $tester->execute(['name' => 'Unsubscribe', '--context' => 'Marketing']);

        $content = file_get_contents($this->src('Marketing/Infrastructure/Email/UnsubscribeComplaintHandler.php'));
        $this->assertStringContainsString('ComplaintHandlerInterface', $content);
        $this->assertStringContainsString('ComplaintNotification', $content);
    }

    public function test_complaint_handler_contains_correct_class_name(): void
    {
        $tester = new CommandTester(new MakeComplaintHandlerCommand($this->engine));
        $tester->execute(['name' => 'Flag', '--context' => 'Trust']);

        $content = file_get_contents($this->src('Trust/Infrastructure/Email/FlagComplaintHandler.php'));
        $this->assertStringContainsString('class FlagComplaintHandler', $content);
        $this->assertStringContainsString('namespace App\\Trust', $content);
    }
}
