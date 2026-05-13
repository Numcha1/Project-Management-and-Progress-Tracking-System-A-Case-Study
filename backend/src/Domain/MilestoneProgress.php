<?php

declare(strict_types=1);

namespace App\Domain;

final class MilestoneProgress
{
    public function __construct(
        public readonly int $id,
        public readonly int $milestoneId,
        public readonly string $actionKey,
        public readonly ?string $beforeStatus,
        public readonly ?string $afterStatus,
        public readonly ?string $note,
        public readonly ?int $actionBy,
        public readonly string $createdAt
    ) {
    }

    public static function fromArray(array $row): self
    {
        return new self(
            (int)($row['id'] ?? 0),
            (int)($row['milestone_id'] ?? 0),
            (string)($row['action_key'] ?? 'updated'),
            isset($row['before_status']) ? (string)$row['before_status'] : null,
            isset($row['after_status']) ? (string)$row['after_status'] : null,
            isset($row['note']) ? (string)$row['note'] : null,
            isset($row['action_by']) ? (int)$row['action_by'] : null,
            (string)($row['created_at'] ?? '')
        );
    }
}
