<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\Listener;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Contract\PasswordHasherInterface;
use Vortos\Auth\Contract\RehashableUserInterface;
use Vortos\Auth\Contract\RehashableUserPersisterInterface;
use Vortos\Auth\Listener\PasswordRehashListener;

final class PasswordRehashListenerTest extends TestCase
{
    public function test_no_op_when_persister_is_null(): void
    {
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects($this->never())->method('needsRehash');

        $user = $this->createMock(RehashableUserInterface::class);

        $listener = new PasswordRehashListener($hasher);
        $listener->onSuccessfulVerify($user, 'password');
    }

    public function test_no_rehash_when_not_needed(): void
    {
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->method('needsRehash')->willReturn(false);

        $persister = $this->createMock(RehashableUserPersisterInterface::class);
        $persister->expects($this->never())->method('save');

        $user = $this->createMock(RehashableUserInterface::class);
        $user->method('getPasswordHash')->willReturn('$argon2id$old_hash');

        $listener = new PasswordRehashListener($hasher, $persister);
        $listener->onSuccessfulVerify($user, 'password');
    }

    public function test_rehashes_and_persists_when_needed(): void
    {
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->method('needsRehash')->willReturn(true);
        $hasher->method('hash')->with('password')->willReturn('$argon2id$new_hash');

        $user = $this->createMock(RehashableUserInterface::class);
        $user->method('getPasswordHash')->willReturn('$argon2id$old_hash');
        $user->expects($this->once())->method('setPasswordHash')->with('$argon2id$new_hash');

        $persister = $this->createMock(RehashableUserPersisterInterface::class);
        $persister->expects($this->once())->method('save')->with($user);

        $listener = new PasswordRehashListener($hasher, $persister);
        $listener->onSuccessfulVerify($user, 'password');
    }

    public function test_passes_correct_plaintext_to_hash(): void
    {
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->method('needsRehash')->willReturn(true);
        $hasher->expects($this->once())->method('hash')->with('my-secret-pw')->willReturn('$new');

        $user = $this->createMock(RehashableUserInterface::class);
        $user->method('getPasswordHash')->willReturn('$old');

        $persister = $this->createMock(RehashableUserPersisterInterface::class);

        $listener = new PasswordRehashListener($hasher, $persister);
        $listener->onSuccessfulVerify($user, 'my-secret-pw');
    }
}
