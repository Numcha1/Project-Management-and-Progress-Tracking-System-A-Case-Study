<?php

declare(strict_types=1);

namespace App\Domain;

final class Semester
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $facultyId,
        public readonly string $academicYear,
        public readonly int $termNo,
        public readonly ?string $startsAt,
        public readonly ?string $endsAt,
        public readonly bool $isActive
    ) {
    }

    public static function fromArray(array $row): self
    {
        return new self(
            (int)($row['id'] ?? 0),
            isset($row['faculty_id']) ? (int)$row['faculty_id'] : null,
            (string)($row['academic_year'] ?? ''),
            (int)($row['term_no'] ?? 1),
            isset($row['starts_at']) ? (string)$row['starts_at'] : null,
            isset($row['ends_at']) ? (string)$row['ends_at'] : null,
            (int)($row['is_active'] ?? 0) === 1
        );
    }
}
