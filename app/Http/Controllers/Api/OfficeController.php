<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OfficeResource;
use App\Models\Office;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OfficeController extends Controller
{
    /** Active company offices a technician can visit to sign the contract and get approved. */
    public function index(): AnonymousResourceCollection
    {
        return OfficeResource::collection(
            Office::where('is_active', true)->orderBy('name')->get(),
        );
    }
}
