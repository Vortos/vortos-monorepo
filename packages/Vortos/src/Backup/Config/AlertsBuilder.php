<?php

declare(strict_types=1);

namespace Vortos\Backup\Config;

/**
 * R8-6: declares backup alerting intent in config. The actual routing already exists — the
 * BackupEventAlertSink (vortos-alerts) is auto-collected and compiled into the backup node — so this
 * builder is a declaration/documentation surface that records the operator's intent (which channel,
 * on which events) for tooling and doctor output. It never suppresses the always-on failure alerts.
 */
final class AlertsBuilder
{
    private bool $onFailure = true;
    private ?string $channel = null;

    public function onFailure(bool $enabled = true): self
    {
        $this->onFailure = $enabled;

        return $this;
    }

    public function channel(string $channel): self
    {
        $this->channel = $channel;

        return $this;
    }

    public function alertsOnFailure(): bool
    {
        return $this->onFailure;
    }

    public function declaredChannel(): ?string
    {
        return $this->channel;
    }
}
