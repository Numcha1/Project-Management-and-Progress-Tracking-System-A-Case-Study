<?php

declare(strict_types=1);

final class AuthController
{
    public function showLogin(): void
    {
        View::render('auth/login');
    }

    public function login(): void
    {
        // TODO: Move login flow from legacy login.php
    }

    public function showRegister(): void
    {
        View::render('auth/register');
    }

    public function register(): void
    {
        // TODO: Move register flow from legacy register.php
    }

    public function logout(): void
    {
        // TODO: Move logout flow from legacy logout.php
    }
}
