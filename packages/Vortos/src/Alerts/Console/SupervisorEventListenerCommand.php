<?php

declare(strict_types=1);

namespace Vortos\Alerts\Console;

use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Event\AlertSource;
use Vortos\Alerts\Severity;

/**
 * A supervisord eventlistener that turns "supervisord has given up on a program" into an alert.
 *
 * WHY THIS EXISTS
 * ---------------
 * supervisord retries a crashing program a handful of times, then moves it to FATAL and stops
 * trying — permanently, and quietly. Nothing outside the container is told. The process is simply
 * gone, `docker ps` still says the container is up, and the only trace is a line in a log nobody is
 * reading.
 *
 * That is how the backup worker died: it exhausted its retries in under fifteen seconds, and the
 * outage was discovered later by the backup-freshness probe noticing that backups had stopped
 * arriving. The freshness probe did its job, but it is a downstream symptom detector — it can only
 * fire after enough time has passed for missing backups to be conclusive. The process itself knew
 * immediately.
 *
 * Every long-running program under supervisord gets this for free once the listener is installed;
 * it is not specific to backups.
 *
 * PROTOCOL
 * --------
 * supervisord speaks a line protocol over the listener's stdin/stdout:
 *   - the listener writes READY and blocks;
 *   - supervisord sends a header line, then exactly `len` bytes of payload;
 *   - the listener writes RESULT/OK and goes back to READY.
 * stdout therefore carries protocol and nothing else — any stray write desynchronises the stream
 * and supervisord marks the listener itself as failing. Everything human-readable goes to stderr,
 * which supervisord captures to the listener's own log.
 *
 * This needs no XML-RPC control socket. The listener is spoken to over its own pipes, so the
 * socket-less posture of these containers is preserved.
 */
#[AsCommand(
    name: 'vortos:alerts:supervisor-events',
    description: 'Run as a supervisord eventlistener and alert when a program enters FATAL.',
)]
final class SupervisorEventListenerCommand extends Command
{
    private const READY = "READY\n";
    private const OK = "RESULT 2\nOK";

    /**
     * @param resource|null $stdin  overridden only by tests; the protocol is a byte stream on the
     *                              process's own pipes, so there is nothing to assert against
     *                              unless both ends can be substituted
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct(
        private readonly AlertDispatcherInterface $dispatcher,
        private $stdin = null,
        private $stdout = null,
        private $stderr = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            // Passed explicitly by the supervisord program block rather than read from the
            // environment: this package's architecture test requires env access to go through
            // EnvLookup, and a listener that needs no environment at all is simpler than one that
            // does.
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Environment label for the alert', 'production')
            ->addOption('node', null, InputOption::VALUE_REQUIRED, 'Node label, to identify which host went quiet')
            ->addOption('max-events', null, InputOption::VALUE_REQUIRED, 'Stop after N events (testing only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = (string) $input->getOption('env');
        $node = (string) ($input->getOption('node') ?? (gethostname() ?: 'unknown'));
        $maxEvents = $input->getOption('max-events') !== null ? (int) $input->getOption('max-events') : null;

        $stdin = $this->stdin ?? fopen('php://stdin', 'rb');
        $stdout = $this->stdout ?? fopen('php://stdout', 'wb');
        $stderr = $this->stderr ?? fopen('php://stderr', 'wb');

        if (!is_resource($stdin) || !is_resource($stdout) || !is_resource($stderr)) {
            return Command::FAILURE;
        }

        $handled = 0;

        while (true) {
            fwrite($stdout, self::READY);
            fflush($stdout);

            $header = fgets($stdin);
            if ($header === false) {
                return Command::SUCCESS; // supervisord closed the pipe: shutting down.
            }

            $meta = $this->parseTokens(trim($header));
            $payload = $this->readPayload($stdin, (int) ($meta['len'] ?? 0));

            try {
                if (($meta['eventname'] ?? '') === 'PROCESS_STATE_FATAL') {
                    $this->alert($this->parseTokens($payload), $env, $node);
                }
            } catch (Throwable $e) {
                // A failed dispatch must never desynchronise the protocol or kill the listener —
                // the listener going down is the same blindness it exists to prevent.
                fwrite($stderr, sprintf("supervisor-events: dispatch failed: %s\n", $e->getMessage()));
            }

            // Always ACK. Returning a failure result makes supervisord requeue the event forever,
            // which turns one undeliverable alert into a hot loop.
            fwrite($stdout, self::OK);
            fflush($stdout);

            $handled++;
            if ($maxEvents !== null && $handled >= $maxEvents) {
                return Command::SUCCESS;
            }
        }
    }

    /** @param resource $stdin */
    private function readPayload($stdin, int $length): string
    {
        $payload = '';
        while ($length > 0 && strlen($payload) < $length) {
            $chunk = fread($stdin, $length - strlen($payload));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $payload .= $chunk;
        }

        return $payload;
    }

    /**
     * Both the header and the payload are space-separated `key:value` pairs.
     *
     * @return array<string, string>
     */
    private function parseTokens(string $line): array
    {
        $out = [];
        foreach (explode(' ', $line) as $token) {
            if ($token === '' || !str_contains($token, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $token, 2);
            $out[$key] = $value;
        }

        return $out;
    }

    /** @param array<string, string> $payload */
    private function alert(array $payload, string $env, string $node): void
    {
        $process = $payload['processname'] ?? 'unknown';

        $this->dispatcher->dispatch(AlertEvent::scrubbed(
            ruleId: 'supervisor.program_fatal.' . $process,
            severity: Severity::Critical,
            title: sprintf('Worker "%s" has stopped permanently', $process),
            summary: sprintf(
                'supervisord gave up restarting "%s" on %s after repeated start failures and moved it '
                . 'to FATAL. It will not be retried; the process is down until the container is '
                . 'recreated. Check its stderr log for the cause.',
                $process,
                $node,
            ),
            source: AlertSource::Deploy,
            env: $env,
            tenantId: null,
            labels: [
                'process' => $process,
                'group' => $payload['groupname'] ?? $process,
                'node' => $node,
            ],
            annotations: [
                'from_state' => $payload['from_state'] ?? 'unknown',
            ],
            links: [],
            occurredAt: new DateTimeImmutable(),
        ));
    }
}
