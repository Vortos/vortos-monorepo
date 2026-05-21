<?php

declare(strict_types=1);

namespace App\User\Domain;

use App\User\Domain\Event\UserRegistered;
use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\Domain\Identity\AggregateId;

final class User extends AggregateRoot
{
    private function __construct(
        private readonly UserId $id,
        private readonly Email $email,
        private string $name,
        private string $passwordHash,
    ) {}

    public static function register(string $name, string $email, string $plainPassword): self
    {
        $id   = UserId::generate();
        $user = new self(
            id:           $id,
            email:        new Email($email),
            name:         $name,
            passwordHash: password_hash($plainPassword, PASSWORD_BCRYPT),
        );

        $user->recordEvent(new UserRegistered(
            email: $email,
            name:  $name,
        ));

        return $user;
    }

    public static function reconstruct(
        UserId $id,
        Email $email,
        string $name,
        string $passwordHash,
        int $version,
    ): self {
        $user = new self(
            id:           $id,
            email:        $email,
            name:         $name,
            passwordHash: $passwordHash,
        );

        $user->restoreVersion($version);

        return $user;
    }

    public function getId(): AggregateId
    {
        return $this->id;
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }
}
