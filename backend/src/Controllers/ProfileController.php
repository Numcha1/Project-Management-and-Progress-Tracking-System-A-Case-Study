<?php

declare(strict_types=1);

final class ProfileController
{
    public function edit(): void
    {
        View::render('profile/edit');
    }
}
