<?php

use Vortos\Messaging\DependencyInjection\VortosMessagingConfig;

return static function (VortosMessagingConfig $config): void {
    // Messaging driver selection is env-driven by the messaging module.
    // php vortos setup writes VORTOS_MESSAGING_DRIVER and VORTOS_MESSAGING_DSN to .env.local.
};
