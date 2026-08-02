<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Technician;
use App\Models\User;
use Illuminate\Http\Request;

trait ResolvesTechnician
{
    /** The caller's own technician profile — 403 if they are not a technician. */
    protected function technicianFor(Request $request): Technician
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Technician|null $technician */
        $technician = $user->technician()->first();
        abort_if($technician === null, 403, 'This account is not a technician.');

        return $technician;
    }
}
