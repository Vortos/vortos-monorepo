<?php

namespace App\User\Domain\Entity;

use App\User\Infrastructure\Repository\DoctrineUserRepository;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

#[Entity(DoctrineUserRepository::class)]
#[Table(name:'users')]
class User
{
    #[Id]
    #[Column(name: 'id', type: 'integer')]
    #[GeneratedValue(strategy: 'AUTO')]
    private int $id;

    #[Column(name: 'name', type: 'string')]
    private string $name;

    #[Column(name: 'email', type: 'string')]
    private string $email;

    #[Column(name: 'status', type: 'boolean', nullable: true)]
    private ?bool $status;

    public function __construct() {}

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

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
