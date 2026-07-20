<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;

class PlatformService
{
    public function account(): User
    {
        return User::where('role', UserRole::Platform)->sole();
    }
}
