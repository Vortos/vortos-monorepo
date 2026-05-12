<?php

declare(strict_types=1);

namespace Vortos\Observability\Config;

enum ObservabilityModule: string
{
    case Auth = 'auth';
    case Authorization = 'authorization';
    case Cache = 'cache';
    case Config = 'config';
    case Cqrs = 'cqrs';
    case Debug = 'debug';
    case Docker = 'docker';
    case Domain = 'domain';
    case FeatureFlags = 'feature_flags';
    case Foundation = 'foundation';
    case Http = 'http';
    case Logger = 'logger';
    case Make = 'make';
    case Mcp = 'mcp';
    case Messaging = 'messaging';
    case Metrics = 'metrics';
    case Migration = 'migration';
    case Observability = 'observability';
    case Persistence = 'persistence';
    case PersistenceDbal = 'persistence_dbal';
    case PersistenceMongo = 'persistence_mongo';
    case PersistenceOrm = 'persistence_orm';
    case Security = 'security';
    case Setup = 'setup';
    case Tracing = 'tracing';

    public static function fromLegacy(string $module): self
    {
        return match ($module) {
            'rate_limit', 'quota', 'audit' => self::Auth,
            'query' => self::Persistence,
            default => self::from($module),
        };
    }
}
