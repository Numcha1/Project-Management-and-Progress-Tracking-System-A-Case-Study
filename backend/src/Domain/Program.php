<?php

declare(strict_types=1);

namespace App\Domain;

final class Program
{
    public function __construct(
        public readonly int $id,
        public readonly int $facultyId,
        public readonly string $code,
        public readonly string $nameTh,
        public readonly ?string $nameEn,
        public readonly string $level,
        public readonly bool $isActive
    ) {
    }

    public static function fromArray(array $row): self
    {
        return new self(
            (int)($row['id'] ?? 0),
            (int)($row['faculty_id'] ?? 0),
            (string)($row['code'] ?? ''),
            (string)($row['name_th'] ?? ''),
            isset($row['name_en']) ? (string)$row['name_en'] : null,
            (string)($row['level'] ?? 'undergraduate'),
            (int)($row['is_active'] ?? 0) === 1
        );
    }
}
