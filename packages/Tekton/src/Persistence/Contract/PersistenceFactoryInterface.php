<?php

namespace Fortizan\Tekton\Persistence\Contract;

use Symfony\Component\Messenger\MessageBusInterface;

interface PersistenceFactoryInterface
{
    public function createSourceWriter(MessageBusInterface $bus, array $connectionParams, array $entityPaths, bool $isDevMode): SourceWriterInterface;
    public function createSourceReader(array $connectionParams, bool $isDevMode): SourceReaderInterface;
    public function createProjectionWriter(array $config): ProjectionWriterInterface;
    public function createProjectionReader(array $config): ProjectionReaderInterface;
}
