<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaleController extends Controller
{
    /**
     * Display the authenticated user's sales, or all sales for an admin.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'seller_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', Rule::in(['pagada', 'cancelada'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Sale::query()
            ->with([
                'user:id,name,email',
                'branch:id,name,city',
            ])
            ->withCount('items');

        $this->applyVisibilityScope($query, $request);

        if (! empty($filters['date_from'])) {
            $query->where('sold_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('sold_at', '<', Carbon::parse($filters['date_to'])->addDay()->startOfDay());
        }

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        if ($request->user()->role === 'admin' && ! empty($filters['seller_id'])) {
            $query->where('user_id', $filters['seller_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $sales = $query
            ->orderByDesc('sold_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return response()->json([
            'success' => true,
            'message' => 'Ventas consultadas correctamente.',
            'data' => $sales->items(),
            'meta' => [
                'pagination' => [
                    'current_page' => $sales->currentPage(),
                    'per_page' => $sales->perPage(),
                    'total' => $sales->total(),
                    'last_page' => $sales->lastPage(),
                    'from' => $sales->firstItem(),
                    'to' => $sales->lastItem(),
                ],
                'filters' => $filters,
            ],
        ]);
    }

    /**
     * Display one sale and its products, respecting the seller's ownership.
     */
    public function show(Request $request, int $sale): JsonResponse
    {
        $query = Sale::query()->with([
            'user:id,name,email',
            'branch:id,name,city',
            'items.product:id,sku,name,category_id',
            'items.product.category:id,name',
        ]);

        $this->applyVisibilityScope($query, $request);
        $saleRecord = $query->findOrFail($sale);

        return response()->json([
            'success' => true,
            'message' => 'Detalle de venta consultado correctamente.',
            'data' => $saleRecord,
            'meta' => null,
        ]);
    }

    /**
     * Register a sale and decrease stock atomically.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'customer_name' => ['required', 'string', 'max:150'],
            'payment_method' => ['required', Rule::in(['efectivo', 'tarjeta', 'transferencia'])],
            'items' => ['required', 'array', 'min:1', 'max:6'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:65535'],
        ], [
            'items.required' => 'Agrega al menos un producto a la venta.',
            'items.min' => 'La venta debe incluir al menos un producto.',
            'items.max' => 'La venta no puede tener más de seis productos distintos.',
            'items.*.product_id.distinct' => 'No repitas productos; ajusta la cantidad del renglón existente.',
        ]);

        $sale = DB::transaction(function () use ($request, $validated): Sale {
            $productIds = collect($validated['items'])
                ->pluck('product_id')
                ->map(fn ($id) => (int) $id)
                ->sort()
                ->values();

            $products = Product::query()
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                abort(422, 'Uno o más productos ya no están disponibles.');
            }

            $subtotalCents = 0;
            $saleItems = [];

            foreach ($validated['items'] as $item) {
                /** @var Product $product */
                $product = $products->get((int) $item['product_id']);
                $quantity = (int) $item['quantity'];

                if (! $product->active) {
                    abort(422, "El producto {$product->name} está inactivo.");
                }

                if ($product->stock < $quantity) {
                    abort(422, "Existencia insuficiente para {$product->name}. Disponible: {$product->stock}.");
                }

                $unitPriceCents = $this->toCents((string) $product->price);
                $lineTotalCents = $unitPriceCents * $quantity;
                $subtotalCents += $lineTotalCents;

                $saleItems[] = [
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $this->fromCents($unitPriceCents),
                    'line_total' => $this->fromCents($lineTotalCents),
                ];

                $product->decrement('stock', $quantity);
            }

            $taxCents = intdiv(($subtotalCents * 16) + 50, 100);

            $sale = Sale::create([
                'folio' => 'VLT-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                'user_id' => $request->user()->id,
                'branch_id' => $validated['branch_id'],
                'customer_name' => $validated['customer_name'],
                'subtotal' => $this->fromCents($subtotalCents),
                'tax' => $this->fromCents($taxCents),
                'total' => $this->fromCents($subtotalCents + $taxCents),
                'payment_method' => $validated['payment_method'],
                'status' => 'pagada',
                'sold_at' => now(),
            ]);

            $sale->items()->createMany($saleItems);

            return $sale;
        }, 3);

        $sale->load([
            'user:id,name,email',
            'branch:id,name,city',
            'items.product:id,sku,name,category_id',
            'items.product.category:id,name',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Venta registrada correctamente.',
            'data' => $sale,
            'meta' => null,
        ], 201);
    }

    /**
     * Cancel a sale as administrator and restore its stock atomically.
     */
    public function cancel(Request $request, int $sale): JsonResponse
    {
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Solo un administrador puede cancelar ventas.',
                'data' => null,
                'meta' => null,
            ], 403);
        }

        $result = DB::transaction(function () use ($sale): array {
            $saleRecord = Sale::query()
                ->whereKey($sale)
                ->lockForUpdate()
                ->firstOrFail();

            if ($saleRecord->status === 'cancelada') {
                return ['already_cancelled' => true, 'sale' => $saleRecord];
            }

            $items = $saleRecord->items()->get();
            $productIds = $items->pluck('product_id')->unique()->sort()->values();

            $products = Product::query()
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                $product = $products->get($item->product_id);

                if (! $product) {
                    abort(409, 'No fue posible devolver las existencias de todos los productos.');
                }

                $product->increment('stock', $item->quantity);
            }

            $saleRecord->update(['status' => 'cancelada']);

            return ['already_cancelled' => false, 'sale' => $saleRecord];
        }, 3);

        if ($result['already_cancelled']) {
            return response()->json([
                'success' => false,
                'message' => 'La venta ya se encuentra cancelada.',
                'data' => null,
                'meta' => null,
            ], 409);
        }

        $saleRecord = $result['sale']->load([
            'user:id,name,email',
            'branch:id,name,city',
            'items.product:id,sku,name,category_id',
            'items.product.category:id,name',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Venta cancelada y existencias devueltas correctamente.',
            'data' => $saleRecord,
            'meta' => null,
        ]);
    }

    private function applyVisibilityScope(Builder $query, Request $request): void
    {
        if ($request->user()->role !== 'admin') {
            $query->where('user_id', $request->user()->id);
        }
    }

    private function toCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0', STR_PAD_LEFT);
    }

    private function fromCents(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
