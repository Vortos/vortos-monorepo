<?php

namespace Fortizan\Tekton\Persistence;

use Fortizan\Tekton\Persistence\Contract\PersistenceManagerInterface;
use Fortizan\Tekton\Persistence\Contract\ProjectionReaderInterface;
use Fortizan\Tekton\Persistence\Contract\SourceReaderInterface;
use Fortizan\Tekton\Persistence\Contract\SourceWriterInterface;

class PersistenceManager implements PersistenceManagerInterface
{
    public function __construct(
        private SourceWriterInterface $writeAdaptor,
        private SourceReaderInterface $readAdaptor,
        private ProjectionReaderInterface $projectionReader
    ){
    }

    public function sourceWriter(): SourceWriterInterface
    {
        return $this->writeAdaptor;
    }

    public function sourceReader(): SourceReaderInterface
    {
        return $this->readAdaptor;
    }

    public function projectionReader(): ProjectionReaderInterface
    {
        return $this->projectionReader;
    }
}