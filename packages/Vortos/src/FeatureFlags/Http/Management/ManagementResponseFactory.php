<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management;

use Symfony\Component\HttpFoundation\Response;
use Vortos\Http\JsonResponse;

final class ManagementResponseFactory
{
    public function ok(mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($this->envelope($data), $status);
    }

    /** Single-item response — alias of {@see ok()} for controllers that read as "return this item". */
    public function item(mixed $data, int $status = 200): JsonResponse
    {
        return $this->ok($data, $status);
    }

    /** @param array<mixed> $items */
    public function list(array $items, ?string $nextCursor, int $total): JsonResponse
    {
        return new JsonResponse([
            'data'       => $items,
            'pagination' => [
                'nextCursor' => $nextCursor,
                'total'      => $total,
            ],
            'meta'       => $this->meta(),
        ]);
    }

    public function created(mixed $data): JsonResponse
    {
        return new JsonResponse($this->envelope($data), 201);
    }

    public function noContent(): Response
    {
        return new Response('', 204);
    }

    private function envelope(mixed $data): array
    {
        return [
            'data' => $data,
            'meta' => $this->meta(),
        ];
    }

    private function meta(): array
    {
        return [
            'requestId' => bin2hex(random_bytes(8)),
            'at'        => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }
}
