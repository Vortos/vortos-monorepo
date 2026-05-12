<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota\Exception;

final class QuotaSubjectNotResolvedException extends \RuntimeException
{
    public function __construct(string $resolverClass)
    {
        parent::__construct(sprintf('Quota subject resolver "%s" did not resolve a subject.', $resolverClass));
    }
}
