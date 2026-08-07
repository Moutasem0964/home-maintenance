<?php

namespace App\Rules;

use App\Models\ServiceCategory;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A selectable service must be a concrete (leaf) category: active, and with no active
 * children. Top-level parents are groupings for browsing, not orderable services.
 */
class LeafServiceCategory implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $category = ServiceCategory::query()->where('is_active', true)->find($value);

        if ($category === null) {
            $fail('The selected :attribute is invalid.');

            return;
        }

        $hasActiveChildren = ServiceCategory::query()
            ->where('parent_id', $category->id)
            ->where('is_active', true)
            ->exists();

        if ($hasActiveChildren) {
            $fail('Please choose a specific service, not a top-level category.');
        }
    }
}
