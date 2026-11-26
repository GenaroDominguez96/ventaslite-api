<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_aggregate_paid_sales_and_ignore_cancelled_sales(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $vendor = User::factory()->create(['role' => 'vendedor']);
        $branch = Branch::factory()->create();
        $category = Category::factory()->create(['name' => 'Abarrotes']);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'price' => '100.00',
            'cost' => '60.00',
            'stock' => 10,
        ]);

        $paidSale = $this->createSale($vendor, $branch, $product, 'pagada', 2, '2026-05-15 12:00:00');
        $this->createSale($vendor, $branch, $product, 'cancelada', 3, '2026-05-16 12:00:00');

        $filters = '?date_from=2026-05-01&date_to=2026-05-31&branch_id='.$branch->id;

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/dashboard'.$filters)
            ->assertOk()
            ->assertJsonPath('data.sales_total', '232.00')
            ->assertJsonPath('data.sales_count', 1)
            ->assertJsonPath('data.average_ticket', '232.0000');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/monthly-sales'.$filters)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.period', '2026-05')
            ->assertJsonPath('data.0.total', '232.00')
            ->assertJsonPath('data.0.sales_count', 1);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/top-products'.$filters.'&by=units')
            ->assertOk()
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.units_sold', 2)
            ->assertJsonPath('data.0.amount_sold', '200.00');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/sellers'.$filters)
            ->assertOk()
            ->assertJsonPath('data.0.seller_id', $vendor->id)
            ->assertJsonPath('data.0.total_sold', '232.00')
            ->assertJsonPath('data.0.sales_count', 1);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/branch-category'.$filters)
            ->assertOk()
            ->assertJsonPath('data.rows.0.branch_id', $branch->id)
            ->assertJsonPath('data.rows.0.categories.'.$category->id, '200.00');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/margins'.$filters)
            ->assertOk()
            ->assertJsonPath('data.0.category_id', $category->id)
            ->assertJsonPath('data.0.units_sold', 2)
            ->assertJsonPath('data.0.gross_profit', '80.00');

        $this->assertDatabaseHas('sales', ['id' => $paidSale->id, 'status' => 'pagada']);
    }

    public function test_salesperson_reports_include_only_their_own_sales(): void
    {
        $vendor = User::factory()->create(['role' => 'vendedor']);
        $otherVendor = User::factory()->create(['role' => 'vendedor']);
        $branch = Branch::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'price' => '100.00',
            'cost' => '60.00',
        ]);

        $ownSale = $this->createSale($vendor, $branch, $product, 'pagada', 1, '2026-05-15 12:00:00');
        $this->createSale($otherVendor, $branch, $product, 'pagada', 4, '2026-05-15 12:00:00');

        $this->actingAs($vendor, 'sanctum')
            ->getJson('/api/reports/dashboard?date_from=2026-05-01&date_to=2026-05-31')
            ->assertOk()
            ->assertJsonPath('data.sales_count', 1)
            ->assertJsonPath('data.sales_total', $ownSale->total);

        $this->actingAs($vendor, 'sanctum')
            ->getJson('/api/reports/top-products?date_from=2026-05-01&date_to=2026-05-31')
            ->assertOk()
            ->assertJsonPath('data.0.units_sold', 1);
    }

    public function test_report_csv_export_is_available(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/reports/dashboard/export?date_from=2026-05-01&date_to=2026-05-31');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertStringContainsString('.csv', $response->headers->get('content-disposition'));
    }

    private function createSale(
        User $vendor,
        Branch $branch,
        Product $product,
        string $status,
        int $quantity,
        string $soldAt
    ): Sale {
        $subtotal = 100 * $quantity;
        $tax = (int) round($subtotal * 0.16);

        $sale = Sale::factory()->create([
            'user_id' => $vendor->id,
            'branch_id' => $branch->id,
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'tax' => number_format($tax, 2, '.', ''),
            'total' => number_format($subtotal + $tax, 2, '.', ''),
            'status' => $status,
            'sold_at' => $soldAt,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => '100.00',
            'line_total' => number_format($subtotal, 2, '.', ''),
        ]);

        return $sale;
    }
}
