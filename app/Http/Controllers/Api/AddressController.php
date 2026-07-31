<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Address\AddressRequest;
use App\Http\Resources\AddressResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AddressController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return AddressResource::collection($user->addresses()->latest()->get());
    }

    public function store(AddressRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $address = $user->addresses()->create($request->validated());

        return (new AddressResource($address))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $address): AddressResource
    {
        /** @var User $user */
        $user = $request->user();

        return new AddressResource($user->addresses()->findOrFail($address));
    }

    public function update(AddressRequest $request, int $address): AddressResource
    {
        /** @var User $user */
        $user = $request->user();

        $model = $user->addresses()->findOrFail($address);
        $model->update($request->validated());

        return new AddressResource($model);
    }

    public function destroy(Request $request, int $address): Response
    {
        /** @var User $user */
        $user = $request->user();

        $user->addresses()->findOrFail($address)->delete();

        return response()->noContent();
    }
}
