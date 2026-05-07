<?php

declare(strict_types=1);

final class StudentController
{
    public function dashboard(): void
    {
        View::render('student/dashboard');
    }

    public function tasks(): void
    {
        View::render('student/all-tasks');
    }
}
