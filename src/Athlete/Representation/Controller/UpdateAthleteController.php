<?php

declare(strict_types=1);

namespace App\Athlete\Representation\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\Attribute\ApiController;
use Vortos\Authorization\Attribute\RequiresPermission;

#[ApiController]
#[Route('/api/athletes/{athleteId}', methods: ['PUT'])]
#[RequiresPermission('athletes.update.own', resourceParam: 'athleteId')]
final class UpdateAthleteController
{
    public function __invoke(Request $request, string $athleteId): JsonResponse
    {
        // If we reach here, authorization passed.
        // $athleteId was checked against $identity->id() by AthletePolicy::canUpdate()

        return new JsonResponse('Authorized to update athlete');
    }
}