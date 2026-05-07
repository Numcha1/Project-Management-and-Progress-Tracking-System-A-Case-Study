<?php

declare(strict_types=1);

final class Auth
{
    public static function userId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function role(): ?string
    {
        return $_SESSION['role'] ?? null;
    }

    public static function isLoggedIn(): bool
    {
        return self::userId() !== null;
    }
}
