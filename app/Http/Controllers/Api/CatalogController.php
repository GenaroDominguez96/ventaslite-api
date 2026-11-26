<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function branches(): JsonResponse
    {
        $branches = Branch::query()
            ->orderBy('name')
            ->get(['id', 'name', 'city']);

        return response()->json([
            'success' => true,
            'message' => 'Sucursales consultadas correctamente.',
            'data' => $branches,
            'meta' => null,
        ]);
    }

    public function categories(): JsonResponse
    {
        $categories = Category::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'message' => 'Categorías consultadas correctamente.',
            'data' => $categories,
            'meta' => null,
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
        ]);

        $query = Product::query()
            ->with('category:id,name')
            ->where('active', true)
            ->where('stock', '>', 0);

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);

            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('sku', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%');
            });
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        $products = $query
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'category_id', 'price', 'stock']);

        return response()->json([
            'success' => true,
            'message' => 'Productos disponibles consultados correctamente.',
            'data' => $products,
            'meta' => [
                'count' => $products->count(),
                'filters' => $filters,
            ],
        ]);
    }
}
