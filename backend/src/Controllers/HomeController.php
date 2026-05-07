<?php

declare(strict_types=1);

final class HomeController
{
    public function dashboard(): void
    {
        // TODO: Replace with role-based redirect logic in router layer.
        View::render('components/placeholder', ['title' => 'Dashboard Entry']);
    }
}
