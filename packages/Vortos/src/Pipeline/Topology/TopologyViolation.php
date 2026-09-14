<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Topology;

/**
 * One reason a compose topology may not reach a host. Carries service names, paths and explanations
 * only — never file contents.
 */
final readonly class TopologyViolation
{
    public function __construct(
        public string $rule,
        public TopologyViolationKind $kind,
        /** Offending service, or '' when the violation is about the topology as a whole. */
        public string $service,
        /** What the violation is about within the service, e.g. the env_file or bind-mount source as written. */
        public string $subject,
        public string $detail,
    ) {}

    public function message(): string
    {
        if ($this->service === '') {
            return sprintf('[%s/%s] %s', $this->rule, $this->kind->value, $this->detail);
        }

        return sprintf('[%s/%s] service "%s", "%s": %s', $this->rule, $this->kind->value, $this->service, $this->subject, $this->detail);
    }

    /** @return array{rule: string, kind: string|int, service: string, subject: string, detail: string} */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'kind' => $this->kind->value,
            'service' => $this->service,
            'subject' => $this->subject,
            'detail' => $this->detail,
        ];
    }
}
