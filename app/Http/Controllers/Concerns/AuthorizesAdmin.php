<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;

trait AuthorizesAdmin
{
    /** 403 unless the caller is an admin. */
    protected function assertAdmin(Request $request): void
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->role === UserRole::Admin, 403, 'Admins only.');
    }
}
