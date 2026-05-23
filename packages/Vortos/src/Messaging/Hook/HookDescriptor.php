<?php

declare(strict_types=1);

namespace Vortos\Messaging\Hook;

/**
 * Immutable descriptor carrying compile-time metadata for one registered hook.
 *
 * Built exclusively by HookDiscoveryCompilerPass from hook attribute data.
 * Stored in HookRegistry keyed by hook type. Never constructed at runtime.
 *
 * Use the class constants when referencing hook types — never raw strings.
 *
 * `on` is populated for AfterHandler hooks only (list of HandlerOutcome cases).
 * Empty `on` means "all terminal outcomes" (AttemptFailed is opt-in).
 * `onFailureOnly` is used by AfterDispatch and AfterConsume hooks only.
 */
final readonly class HookDescriptor
{
    public const string BEFORE_DISPATCH = 'before_dispatch';
    public const string AFTER_DISPATCH  = 'after_dispatch';
    public const string PRE_SEND        = 'pre_send';
    public const string BEFORE_CONSUME  = 'before_consume';
    public const string AFTER_CONSUME   = 'after_consume';
    public const string BEFORE_HANDLER  = 'before_handler';
    public const string AFTER_HANDLER   = 'after_handler';

    /** @param list<HandlerOutcome> $on */
    public function __construct(
        public string  $hookType,
        public string  $serviceId,
        public ?string $eventFilter    = null,
        public ?string $consumerFilter = null,
        public int     $priority       = 0,
        public bool    $onFailureOnly  = false,
        public array   $on             = [],
    ) {}
}
