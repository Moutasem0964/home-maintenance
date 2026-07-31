<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceCategoryResource;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $categories = ServiceCategory::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->with(['children' => fn (Relation $query) => $query->where('is_active', true)])
            ->get();

        return ServiceCategoryResource::collection($categories);
    }
}
