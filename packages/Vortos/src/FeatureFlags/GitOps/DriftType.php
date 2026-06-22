<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\GitOps;

enum DriftType: string
{
    case FieldMismatch = 'field_mismatch';
    case MissingInRuntime = 'missing_in_runtime';
    case UndeclaredInFile = 'undeclared_in_file';
}
