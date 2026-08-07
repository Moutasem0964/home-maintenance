<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;

class ProfileController extends Controller
{
    /** Update the authenticated user's own profile (name, avatar). Works for client and technician. */
    public function update(UpdateProfileRequest $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        $user->update($request->validated());

        return new UserResource($user);
    }
}
