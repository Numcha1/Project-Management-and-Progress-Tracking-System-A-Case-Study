<?php

declare(strict_types=1);

final class AdminController
{
    public function dashboard(): void
    {
        View::render('admin/dashboard');
    }

    public function createAdmin(): void
    {
        View::render('admin/create-admin');
    }
}
