<?php

declare(strict_types=1);

namespace App\Domain;

final class Committee
{
    public function __construct(
        public readonly int $id,
        public readonly int $proposalId,
        public readonly int $quorumMin,
        public readonly string $status
    ) {
    }

    public static function fromArray(array $row): self
    {
        return new self(
            (int)($row['id'] ?? 0),
            (int)($row['proposal_id'] ?? 0),
            (int)($row['quorum_min'] ?? 1),
            (string)($row['status'] ?? 'draft')
        );
    }
}
