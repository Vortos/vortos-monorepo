<?php

declare(strict_types=1);

namespace Vortos\Release\Schema;

enum FingerprintRelation: string
{
    case Equal = 'equal';
    case Subset = 'subset';
    case Superset = 'superset';
    case Overlapping = 'overlapping';
    case Disjoint = 'disjoint';
}
