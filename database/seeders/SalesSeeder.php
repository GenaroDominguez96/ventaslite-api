<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SalesSeeder extends Seeder
{
    public function run(): void
    {
        // The transaction makes a second seeding run safe: either all demo sales
        // and their items exist, or none of them do.
        if (Sale::query()->exists()) {
            return;
        }

        $faker = fake('es_MX');
        $products = Product::query()
            ->where('active', true)
            ->get(['id', 'price'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'price_cents' => $this->toCents((string) $product->price),
            ])
            ->all();

        $sellerIds = User::query()
            ->where('role', 'vendedor')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $branchIds = DB::table('branches')->orderBy('id')->pluck('id')->all();

        if (count($products) < 3 || count($sellerIds) !== 8 || count($branchIds) !== 4) {
            throw new \RuntimeException('Faltan catálogos necesarios para generar las ventas de prueba.');
        }

        $months = $this->weightedMonths();
        $now = now()->toDateTimeString();
        $salesRows = [];
        $saleItemsByFolio = [];

        DB::transaction(function () use (
            $faker,
            $products,
            $sellerIds,
            $branchIds,
            $months,
            $now,
            &$salesRows,
            &$saleItemsByFolio
        ): void {
            for ($index = 1; $index <= 2500; $index++) {
                $folio = sprintf('VLT-%s-%05d', now()->format('Y'), $index);
                $monthRange = $faker->randomElement($months);
                $soldAt = $faker->dateTimeBetween($monthRange[0], $monthRange[1])->format('Y-m-d H:i:s');

                $branchRoll = $faker->numberBetween(1, 100);
                $branchId = match (true) {
                    $branchRoll <= 40 => $branchIds[0],
                    $branchRoll <= 75 => $branchIds[1],
                    $branchRoll <= 95 => $branchIds[2],
                    default => $branchIds[3],
                };

                $selectedProducts = $faker->randomElements($products, 3);
                $items = [];
                $subtotalCents = 0;

                foreach ($selectedProducts as $product) {
                    $quantity = $faker->numberBetween(1, 5);
                    $lineTotalCents = $product['price_cents'] * $quantity;
                    $subtotalCents += $lineTotalCents;

                    $items[] = [
                        'product_id' => $product['id'],
                        'quantity' => $quantity,
                        'unit_price' => $this->fromCents($product['price_cents']),
                        'line_total' => $this->fromCents($lineTotalCents),
                    ];
                }

                $taxCents = intdiv(($subtotalCents * 16) + 50, 100);
                $status = $index % 20 === 0 ? 'cancelada' : 'pagada';

                $sale = Sale::factory()->make([
                    'folio' => $folio,
                    'user_id' => $faker->randomElement($sellerIds),
                    'branch_id' => $branchId,
                    'customer_name' => $faker->name(),
                    'subtotal' => $this->fromCents($subtotalCents),
                    'tax' => $this->fromCents($taxCents),
                    'total' => $this->fromCents($subtotalCents + $taxCents),
                    'payment_method' => $faker->randomElement(['efectivo', 'tarjeta', 'transferencia']),
                    'status' => $status,
                    'sold_at' => $soldAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $salesRows[] = $sale->getAttributes();
                $saleItemsByFolio[$folio] = $items;
            }

            foreach (array_chunk($salesRows, 500) as $chunk) {
                DB::table('sales')->insert($chunk);
            }

            $saleIds = DB::table('sales')
                ->whereIn('folio', array_keys($saleItemsByFolio))
                ->pluck('id', 'folio');

            $itemRows = [];

            foreach ($saleItemsByFolio as $folio => $items) {
                foreach ($items as $item) {
                    $saleItem = SaleItem::factory()->make([
                        'sale_id' => $saleIds[$folio],
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'line_total' => $item['line_total'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $itemRows[] = $saleItem->getAttributes();
                }
            }

            foreach (array_chunk($itemRows, 1000) as $chunk) {
                DB::table('sale_items')->insert($chunk);
            }
        });
    }

    /**
     * Return month intervals for the last 18 months, weighting December three times.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function weightedMonths(): array
    {
        $start = now()->subMonths(18)->startOfDay();
        $currentMonth = now()->startOfMonth();
        $cursor = $start->copy()->startOfMonth();
        $months = [];

        while ($cursor <= $currentMonth) {
            $monthStart = $cursor->lt($start) ? $start->copy() : $cursor->copy();
            $monthEnd = $cursor->copy()->endOfMonth()->endOfDay();

            if ($monthEnd > now()) {
                $monthEnd = now();
            }

            $range = [
                $monthStart->toDateTimeString(),
                $monthEnd->toDateTimeString(),
            ];

            $weight = $cursor->month === 12 ? 3 : 1;

            for ($index = 0; $index < $weight; $index++) {
                $months[] = $range;
            }

            $cursor->addMonth();
        }

        return $months;
    }

    private function toCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function fromCents(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
