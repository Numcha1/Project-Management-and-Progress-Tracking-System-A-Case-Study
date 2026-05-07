<?php

declare(strict_types=1);

final class TeacherController
{
    public function dashboard(): void
    {
        View::render('teacher/dashboard');
    }
}
