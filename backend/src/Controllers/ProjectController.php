<?php

declare(strict_types=1);

final class ProjectController
{
    public function detail(): void
    {
        View::render('project/detail');
    }
}
