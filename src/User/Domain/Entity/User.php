<?php

namespace App\User\Domain\Entity;

use App\User\Domain\Event\UserCreatedEvent;
use App\User\Infrastructure\Repository\DoctrineUserRepository;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Fortizan\Tekton\Domain\AggregateRootInterface;
use Fortizan\Tekton\Domain\AggregateRootTrait;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[Entity(DoctrineUserRepository::class)]
#[Table(name: 'users')]
class User implements AggregateRootInterface
{

    use AggregateRootTrait;

    private function __construct(
        #[Id]
        #[Column(name: 'id', type: 'uuid')]
        #[GeneratedValue(strategy: 'NONE')]
        private UuidV7 $id,

        #[Column(name: 'name', type: 'string')]
        private string $name,

        #[Column(name: 'email', type: 'string')]
        private string $email,

        #[Column(name: 'status', type: 'boolean', nullable: true)]
        private ?bool $status,
    ) {}

    public static function registerUser(string $name, string $email, ?bool $status): self
    {
        $user = new User(
            Uuid::v7(),
            $name,
            $email,
            $status
        );

        $user->recordEvent(
            new UserCreatedEvent(
                $user->id,
                $user->name,
                $user->email
            )
        );

        return $user;
    }

    public function getId(): string
    {
        return $this->id->toRfc4122();
    }

    // public function setId(int $id): void
    // {
    //     $this->id = $id;
    // }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }
}
