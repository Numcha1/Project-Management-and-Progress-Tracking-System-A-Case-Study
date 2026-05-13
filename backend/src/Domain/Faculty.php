<?php

declare(strict_types=1);

namespace App\Domain;

final class Faculty
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $nameTh,
        public readonly ?string $nameEn,
        public readonly string $tenantDbName,
        public readonly bool $isActive
    ) {
    }

    public static function fromArray(array $row): self
    {
        return new self(
            (int)($row['id'] ?? 0),
            (string)($row['code'] ?? ''),
            (string)($row['name_th'] ?? ''),
            isset($row['name_en']) ? (string)$row['name_en'] : null,
            (string)($row['tenant_db_name'] ?? ''),
            (int)($row['is_active'] ?? 0) === 1
        );
    }
}
