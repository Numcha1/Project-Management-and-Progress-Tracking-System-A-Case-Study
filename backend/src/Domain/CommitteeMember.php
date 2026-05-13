<?php

declare(strict_types=1);

namespace App\Domain;

final class CommitteeMember
{
    public function __construct(
        public readonly int $id,
        public readonly int $committeeId,
        public readonly int $userId,
        public readonly string $roleKey,
        public readonly bool $canVote
    ) {
    }

    public static function fromArray(array $row): self
    {
        return new self(
            (int)($row['id'] ?? 0),
            (int)($row['committee_id'] ?? 0),
            (int)($row['user_id'] ?? 0),
            (string)($row['role_key'] ?? 'reviewer'),
            (int)($row['can_vote'] ?? 1) === 1
        );
    }
}
