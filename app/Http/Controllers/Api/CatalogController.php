<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
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

    public function sellers(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permiso para consultar el catálogo de vendedores.',
                'data' => null,
                'meta' => null,
            ], 403);
        }

        $sellers = User::query()
            ->where('role', 'vendedor')
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return response()->json([
            'success' => true,
            'message' => 'Vendedores consultados correctamente.',
            'data' => $sellers,
            'meta' => null,
        ]);
    }

    public function products(): JsonResponse
    {
        $products = Product::query()
            ->where('active', true)
            ->where('stock', '>', 0)
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'price', 'stock']);

        return response()->json([
            'success' => true,
            'message' => 'Productos disponibles consultados correctamente.',
            'data' => $products,
            'meta' => null,
        ]);
    }
}
