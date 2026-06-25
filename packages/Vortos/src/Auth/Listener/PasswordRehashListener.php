<?php
declare(strict_types=1);

namespace Vortos\Auth\Listener;

use Psr\Log\LoggerInterface;
use Vortos\Auth\Contract\PasswordHasherInterface;
use Vortos\Auth\Contract\RehashableUserInterface;
use Vortos\Auth\Contract\RehashableUserPersisterInterface;

final class PasswordRehashListener
{
    public function __construct(
        private PasswordHasherInterface $hasher,
        private ?RehashableUserPersisterInterface $persister = null,
        private ?LoggerInterface $logger = null,
    ) {}

    public function onSuccessfulVerify(RehashableUserInterface $user, string $plaintext): void
    {
        if ($this->persister === null) {
            return;
        }

        if (!$this->hasher->needsRehash($user->getPasswordHash())) {
            return;
        }

        $user->setPasswordHash($this->hasher->hash($plaintext));
        $this->persister->save($user);

        $this->logger?->info('auth.password_rehashed', [
            'reason' => 'cost_parameters_changed',
        ]);
    }
}
