<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Category;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);
        $current = $this->salesQuery($filters, $request);
        $summary = (clone $current)
            ->selectRaw('COALESCE(SUM(sales.total), 0) AS sales_total')
            ->selectRaw('COUNT(sales.id) AS sales_count')
            ->selectRaw('CAST(COALESCE(AVG(sales.total), 0) AS DECIMAL(14,2)) AS average_ticket')
            ->first();

        $days = $filters['date_from']->diffInDays($filters['date_to']) + 1;
        $previousFilters = $filters;
        $previousFilters['date_from'] = $filters['date_from']->copy()->subDays($days);
        $previousFilters['date_to'] = $filters['date_from']->copy()->subSecond();

        $previous = $this->salesQuery($previousFilters, $request)
            ->selectRaw('COALESCE(SUM(sales.total), 0) AS sales_total')
            ->first();

        $currentCents = $this->decimalToCents($summary->sales_total);
        $previousCents = $this->decimalToCents($previous->sales_total);
        $change = $previousCents > 0
            ? (($currentCents - $previousCents) / $previousCents) * 100
            : ($currentCents > 0 ? 100 : 0);

        return $this->success([
            'sales_total' => $this->centsToDecimal($currentCents),
            'sales_count' => (int) $summary->sales_count,
            'average_ticket' => $this->money($summary->average_ticket),
            'change_percentage' => round($change, 2),
            'previous_sales_total' => $this->centsToDecimal($previousCents),
        ], 'Indicadores del dashboard consultados correctamente.', $this->filterMeta($filters));
    }

    public function monthlySales(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);
        $yearExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y', sales.sold_at)"
            : 'YEAR(sales.sold_at)';
        $monthExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%m', sales.sold_at) AS INTEGER)"
            : 'MONTH(sales.sold_at)';

        $aggregates = $this->salesQuery($filters, $request)
            ->selectRaw($yearExpression.' AS year')
            ->selectRaw($monthExpression.' AS month')
            ->selectRaw('COALESCE(SUM(sales.total), 0) AS total')
            ->selectRaw('COUNT(sales.id) AS sales_count')
            ->groupByRaw($yearExpression.', '.$monthExpression)
            ->orderByRaw('year, month')
            ->get()
            ->keyBy(fn ($row) => sprintf('%04d-%02d', $row->year, $row->month));

        $months = [];
        $cursor = $filters['date_from']->copy()->startOfMonth();
        $lastMonth = $filters['date_to']->copy()->startOfMonth();

        while ($cursor <= $lastMonth) {
            $key = $cursor->format('Y-m');
            $aggregate = $aggregates->get($key);

            $months[] = [
                'period' => $key,
                'label' => ucfirst($cursor->locale('es_MX')->translatedFormat('M y')),
                'total' => $this->money($aggregate?->total ?? '0.00'),
                'sales_count' => (int) ($aggregate?->sales_count ?? 0),
            ];

            $cursor->addMonth();
        }

        return $this->success(
            $months,
            'Ventas mensuales consultadas correctamente.',
            $this->filterMeta($filters)
        );
    }

    public function topProducts(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);
        $validated = $request->validate([
            'by' => ['nullable', 'in:units,amount'],
        ]);
        $by = $validated['by'] ?? 'units';

        $products = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->where('sales.status', 'pagada')
            ->whereBetween('sales.sold_at', [
                $filters['date_from'],
                $filters['date_to'],
            ])
            ->when($filters['branch_id'], fn ($query) => $query->where('sales.branch_id', $filters['branch_id']))
            ->when(
                $request->user()->role !== 'admin',
                fn ($query) => $query->where('sales.user_id', $request->user()->id)
            )
            ->select([
                'products.id',
                'products.sku',
                'products.name',
                'categories.name as category_name',
            ])
            ->selectRaw('SUM(sale_items.quantity) AS units_sold')
            ->selectRaw('CAST(SUM(sale_items.line_total) AS DECIMAL(14,2)) AS amount_sold')
            ->groupBy('products.id', 'products.sku', 'products.name', 'categories.name')
            ->orderByDesc($by === 'amount' ? 'amount_sold' : 'units_sold')
            ->orderBy('products.name')
            ->limit(10)
            ->get()
            ->map(function ($product) {
                $product->amount_sold = $this->money($product->amount_sold);

                return $product;
            });

        return $this->success(
            $products,
            'Top 10 de productos consultado correctamente.',
            array_merge($this->filterMeta($filters), ['ordered_by' => $by])
        );
    }

    public function sellers(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);

        $ranking = $this->salesQuery($filters, $request)
            ->join('users', 'users.id', '=', 'sales.user_id')
            ->select([
                'users.id as seller_id',
                'users.name as seller_name',
            ])
            ->selectRaw('CAST(SUM(sales.total) AS DECIMAL(14,2)) AS total_sold')
            ->selectRaw('COUNT(sales.id) AS sales_count')
            ->selectRaw('CAST(AVG(sales.total) AS DECIMAL(14,2)) AS average_ticket')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_sold')
            ->orderBy('users.name')
            ->get()
            ->map(function ($seller) {
                $seller->total_sold = $this->money($seller->total_sold);
                $seller->average_ticket = $this->money($seller->average_ticket);

                return $seller;
            });

        return $this->success(
            $ranking,
            'Ranking de vendedores consultado correctamente.',
            $this->filterMeta($filters)
        );
    }

    public function branchCategory(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);

        $aggregates = DB::table('sales')
            ->join('branches', 'branches.id', '=', 'sales.branch_id')
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->where('sales.status', 'pagada')
            ->whereBetween('sales.sold_at', [
                $filters['date_from'],
                $filters['date_to'],
            ])
            ->when($filters['branch_id'], fn ($query) => $query->where('sales.branch_id', $filters['branch_id']))
            ->when(
                $request->user()->role !== 'admin',
                fn ($query) => $query->where('sales.user_id', $request->user()->id)
            )
            ->select([
                'branches.id as branch_id',
                'branches.name as branch_name',
                'categories.id as category_id',
                'categories.name as category_name',
            ])
            ->selectRaw('CAST(SUM(sale_items.line_total) AS DECIMAL(14,2)) AS total')
            ->groupBy(
                'branches.id',
                'branches.name',
                'categories.id',
                'categories.name'
            )
            ->orderBy('branches.name')
            ->orderBy('categories.name')
            ->get();

        $branches = Branch::query()
            ->when($filters['branch_id'], fn ($query) => $query->whereKey($filters['branch_id']))
            ->orderBy('name')
            ->get(['id', 'name']);

        $categories = Category::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $aggregates = $aggregates->map(function ($row) {
            $row->total = $this->money($row->total);

            return $row;
        });

        $matrix = [];
        foreach ($branches as $branch) {
            $categoryValues = [];
            foreach ($categories as $category) {
                $match = $aggregates->first(
                    fn ($row) => (int) $row->branch_id === (int) $branch->id
                        && (int) $row->category_id === (int) $category->id
                );

                $categoryValues[(string) $category->id] = $match?->total ?? '0.00';
            }

            $matrix[] = [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'categories' => $categoryValues,
                'total' => $this->centsToDecimal($aggregates
                    ->where('branch_id', $branch->id)
                    ->sum(fn ($row) => $this->decimalToCents($row->total))),
            ];
        }

        return $this->success([
            'branches' => $branches,
            'categories' => $categories,
            'rows' => $matrix,
        ], 'Matriz de ventas por sucursal y categoría consultada correctamente.', $this->filterMeta($filters));
    }

    public function margins(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);

        $margins = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->where('sales.status', 'pagada')
            ->whereBetween('sales.sold_at', [
                $filters['date_from'],
                $filters['date_to'],
            ])
            ->when($filters['branch_id'], fn ($query) => $query->where('sales.branch_id', $filters['branch_id']))
            ->when(
                $request->user()->role !== 'admin',
                fn ($query) => $query->where('sales.user_id', $request->user()->id)
            )
            ->select([
                'categories.id as category_id',
                'categories.name as category_name',
            ])
            ->selectRaw('SUM(sale_items.quantity) AS units_sold')
            ->selectRaw('CAST(SUM((sale_items.unit_price - products.cost) * sale_items.quantity) AS DECIMAL(14,2)) AS gross_profit')
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('gross_profit')
            ->get()
            ->map(function ($row) {
                $row->gross_profit = $this->money($row->gross_profit);

                return $row;
            });

        return $this->success(
            $margins,
            'Margen bruto por categoría consultado correctamente.',
            $this->filterMeta($filters)
        );
    }

    public function export(Request $request, string $report): StreamedResponse|JsonResponse
    {
        $supportedReports = [
            'dashboard',
            'monthly-sales',
            'top-products',
            'sellers',
            'branch-category',
            'margins',
        ];

        if (! in_array($report, $supportedReports, true)) {
            return response()->json([
                'success' => false,
                'message' => 'El reporte solicitado no existe.',
                'data' => null,
                'meta' => null,
            ], 404);
        }

        $payload = match ($report) {
            'dashboard' => $this->dashboard($request)->getData(true)['data'],
            'monthly-sales' => $this->monthlySales($request)->getData(true)['data'],
            'top-products' => $this->topProducts($request)->getData(true)['data'],
            'sellers' => $this->sellers($request)->getData(true)['data'],
            'branch-category' => $this->branchCategory($request)->getData(true)['data'],
            'margins' => $this->margins($request)->getData(true)['data'],
        };

        $rows = $this->csvRows($report, $payload);
        $filename = sprintf(
            'ventaslite_%s_%s.csv',
            Str::snake($report),
            now()->format('Ymd_His')
        );

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                fputcsv($output, array_map([$this, 'safeCsvValue'], $row));
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{date_from: Carbon, date_to: Carbon, branch_id: int|null}
     */
    private function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'by' => ['sometimes', 'nullable', 'in:units,amount'],
        ]);

        $from = isset($validated['date_from'])
            ? Carbon::parse($validated['date_from'])->startOfDay()
            : now()->subMonths(11)->startOfMonth();

        $to = isset($validated['date_to'])
            ? Carbon::parse($validated['date_to'])->endOfDay()
            : now()->endOfDay();

        if ($to->lt($from)) {
            throw ValidationException::withMessages([
                'date_to' => 'La fecha final debe ser igual o posterior a la fecha inicial.',
            ]);
        }

        return [
            'date_from' => $from,
            'date_to' => $to,
            'branch_id' => isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
        ];
    }

    private function salesQuery(array $filters, Request $request): Builder
    {
        return DB::table('sales')
            ->where('sales.status', 'pagada')
            ->whereBetween('sales.sold_at', [
                $filters['date_from'],
                $filters['date_to'],
            ])
            ->when(
                $filters['branch_id'],
                fn ($query) => $query->where('sales.branch_id', $filters['branch_id'])
            )
            ->when(
                $request->user()->role !== 'admin',
                fn ($query) => $query->where('sales.user_id', $request->user()->id)
            );
    }

    private function success(mixed $data, string $message, ?array $meta = null): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => $meta,
        ]);
    }

    private function filterMeta(array $filters): array
    {
        return [
            'filters' => [
                'date_from' => $filters['date_from']->toDateString(),
                'date_to' => $filters['date_to']->toDateString(),
                'branch_id' => $filters['branch_id'],
            ],
        ];
    }

    private function csvRows(string $report, mixed $payload): array
    {
        return match ($report) {
            'dashboard' => [
                ['Indicador', 'Valor'],
                ['Ventas totales', $payload['sales_total']],
                ['Número de ventas', $payload['sales_count']],
                ['Ticket promedio', $payload['average_ticket']],
                ['Cambio contra periodo anterior (%)', $payload['change_percentage']],
                ['Ventas periodo anterior', $payload['previous_sales_total']],
            ],
            'monthly-sales' => array_merge(
                [['Periodo', 'Ventas totales', 'Número de ventas']],
                array_map(fn ($row) => [$row['label'], $row['total'], $row['sales_count']], $payload)
            ),
            'top-products' => array_merge(
                [['SKU', 'Producto', 'Categoría', 'Unidades vendidas', 'Importe vendido']],
                array_map(fn ($row) => [
                    $row['sku'], $row['name'], $row['category_name'], $row['units_sold'], $row['amount_sold'],
                ], $payload)
            ),
            'sellers' => array_merge(
                [['Vendedor', 'Total vendido', 'Número de ventas', 'Ticket promedio']],
                array_map(fn ($row) => [
                    $row['seller_name'], $row['total_sold'], $row['sales_count'], $row['average_ticket'],
                ], $payload)
            ),
            'branch-category' => $this->branchCategoryCsvRows($payload),
            'margins' => array_merge(
                [['Categoría', 'Unidades vendidas', 'Utilidad bruta']],
                array_map(fn ($row) => [
                    $row['category_name'], $row['units_sold'], $row['gross_profit'],
                ], $payload)
            ),
        };
    }

    private function branchCategoryCsvRows(array $payload): array
    {
        $rows = [['Sucursal', 'Categoría', 'Importe vendido']];

        foreach ($payload['rows'] as $branch) {
            foreach ($payload['categories'] as $category) {
                $rows[] = [
                    $branch['branch_name'],
                    $category['name'],
                    $branch['categories'][(string) $category['id']] ?? '0.00',
                ];
            }
        }

        return $rows;
    }


    private function money(mixed $amount): string
    {
        return $this->centsToDecimal($this->decimalToCents($amount));
    }

    private function decimalToCents(mixed $amount): int
    {
        $value = trim((string) $amount);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad($fraction, 3, '0');
        $cents = ((int) $whole * 100) + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $cents++;
        }

        return $negative ? -$cents : $cents;
    }

    private function centsToDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private function safeCsvValue(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^[=+@\-]/', $value)) {
            return "'".$value;
        }

        return $value;
    }
}
