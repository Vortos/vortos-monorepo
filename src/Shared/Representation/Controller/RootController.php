<?php

declare(strict_types=1);

namespace App\Shared\Representation\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\Http\Attribute\ApiController;

#[ApiController]
#[Route('/', name: 'root', methods: ['GET'])]
final class RootController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'name'    => 'Vortos API',
            'version' => '1.0.0-alpha',
            'status'  => 'operational',
            'docs'    => 'https://docs.vortos.dev',
        ]);
    }
}
