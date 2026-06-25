<?php

declare(strict_types=1);

namespace Vortos\Release\Tagging;

final class TaggingTransaction
{
    /** @param list<AppliedTag> $tags */
    public function __construct(
        public readonly string $id,
        public readonly \DateTimeImmutable $createdAt,
        public array $tags,
        public TaggingStatus $status,
    ) {}

    public function addTag(AppliedTag $tag): void
    {
        $this->tags[] = $tag;
    }

    public function markComplete(): void
    {
        $this->status = TaggingStatus::Complete;
    }

    public function markPartial(): void
    {
        $this->status = TaggingStatus::Partial;
    }

    public function markUndone(): void
    {
        $this->status = TaggingStatus::Undone;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'tags' => array_map(static fn (AppliedTag $t) => $t->toArray(), $this->tags),
            'status' => $this->status->value,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            createdAt: new \DateTimeImmutable($data['created_at']),
            tags: array_map(
                static fn (array $t) => AppliedTag::fromArray($t),
                $data['tags'],
            ),
            status: TaggingStatus::from($data['status']),
        );
    }
}
