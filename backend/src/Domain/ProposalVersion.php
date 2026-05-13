<?php

declare(strict_types=1);

namespace App\Domain;

final class ProposalVersion
{
    public function __construct(
        public readonly int $id,
        public readonly int $proposalId,
        public readonly int $versionNo,
        public readonly string $statusSnapshot,
        public readonly int $changedBy,
        public readonly string $createdAt
    ) {
    }

    public static function fromArray(array $row): self
    {
        return new self(
            (int)($row['id'] ?? 0),
            (int)($row['proposal_id'] ?? 0),
            (int)($row['version_no'] ?? 1),
            (string)($row['status_snapshot'] ?? 'draft'),
            (int)($row['changed_by'] ?? 0),
            (string)($row['created_at'] ?? '')
        );
    }
}
