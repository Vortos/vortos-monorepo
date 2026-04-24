<?php

namespace App\Athlete\Application\Command;

use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Authorization\Engine\PolicyEngine;
use Vortos\Cqrs\Attribute\AsCommandHandler;

#[AsCommandHandler]
final class UpdateAthleteHandler
{
    public function __construct(
        private PolicyEngine $policy,
        private CurrentUserProvider $currentUser,
        // private AthleteRepository $repository,
    ) {}

    public function __invoke(UpdateAthleteCommand $command): Athlete
    {
        // $athlete = $this->repository->findById($command->athleteId);

        $this->policy->authorize(
            $this->currentUser->get(),
            'athletes.update.own',
            (string) $athlete->getId(),
        );

        // Proceeds only if authorized
        $athlete->update($command->name, $command->dateOfBirth);
        // $this->repository->save($athlete);
        return $athlete;
    }
}