<?php
declare(strict_types=1);

namespace Vortos\Tracing\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class DisableTracing {}
