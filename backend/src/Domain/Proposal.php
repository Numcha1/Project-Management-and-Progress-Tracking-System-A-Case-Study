<?php

declare(strict_types=1);

namespace App\Domain;

final class Proposal
{
    public function __construct(
        public readonly int $id,
        public readonly int $projectId,
        public readonly int $submittedBy,
        public readonly int $currentVersionNo,
        public readonly string $status,
        public readonly ?string $programCode,
        public readonly ?string $semesterLabel,
        public readonly string $title
    ) {
    }

    public static function fromArray(array $row): self
    {
        return new self(
            (int)($row['id'] ?? 0),
            (int)($row['project_id'] ?? 0),
            (int)($row['submitted_by'] ?? 0),
            (int)($row['current_version_no'] ?? 1),
            (string)($row['status'] ?? 'draft'),
            isset($row['program_code']) ? (string)$row['program_code'] : null,
            isset($row['semester_label']) ? (string)$row['semester_label'] : null,
            (string)($row['title'] ?? '')
        );
    }
}
