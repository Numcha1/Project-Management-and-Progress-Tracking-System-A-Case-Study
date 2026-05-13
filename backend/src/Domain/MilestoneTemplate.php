<?php

declare(strict_types=1);

namespace App\Domain;

final class MilestoneTemplate
{
    public function __construct(
        public readonly int $id,
        public readonly string $programCode,
        public readonly string $templateName,
        public readonly bool $isActive
    ) {
    }

    public static function fromArray(array $row): self
    {
        return new self(
            (int)($row['id'] ?? 0),
            (string)($row['program_code'] ?? ''),
            (string)($row['template_name'] ?? ''),
            (int)($row['is_active'] ?? 0) === 1
        );
    }
}
