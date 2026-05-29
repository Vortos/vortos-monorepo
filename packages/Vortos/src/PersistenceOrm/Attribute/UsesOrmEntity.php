<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Attribute;

/**
 * Declares which Doctrine entity class a repository persists.
 *
 * OrmRepositoryCompilerPass reads this attribute at compile time, creates a
 * named OrmStore service wired with the EntityManager and entity class, and
 * injects it as the $store constructor argument of the repository.
 * No services.php entry needed.
 *
 * ## Usage
 *
 *   #[UsesOrmEntity(User::class)]
 *   final class UserOrmRepository implements UserRepositoryInterface
 *   {
 *       public function __construct(private readonly OrmStore $store) {}
 *
 *       public function save(User $user): void   { $this->store->save($user); }
 *       public function delete(User $user): void { $this->store->delete($user); }
 *
 *       public function findByEmail(Email $email): ?User
 *       {
 *           /** @var User|null *\/
 *           return $this->store->createQueryBuilder()
 *               ->select('u')
 *               ->from(User::class, 'u')
 *               ->where('u.email = :email')
 *               ->setParameter('email', (string) $email)
 *               ->getQuery()
 *               ->getOneOrNullResult();
 *       }
 *   }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class UsesOrmEntity
{
    public function __construct(
        public readonly string $entityClass,
    ) {}
}
