<?php

namespace App\Modules\Videos\Http\Controllers;

use App\Modules\Videos\Models\Category;
use Illuminate\Http\JsonResponse;

final class CategoryController
{
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'items' => Category::query()->where('is_active', true)->orderBy('sort_order')->get()
                ->map(fn (Category $c) => ['slug' => $c->slug, 'name' => $c->name])->all(),
        ]);
    }
}
