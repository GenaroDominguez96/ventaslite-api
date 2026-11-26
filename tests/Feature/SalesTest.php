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

class SalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_can_only_list_and_view_own_sales(): void
    {
        $vendor = User::factory()->create(['role' => 'vendedor']);
        $otherVendor = User::factory()->create(['role' => 'vendedor']);
        $branch = Branch::factory()->create();

        $ownSale = Sale::factory()->create([
            'user_id' => $vendor->id,
            'branch_id' => $branch->id,
        ]);

        $otherSale = Sale::factory()->create([
            'user_id' => $otherVendor->id,
            'branch_id' => $branch->id,
        ]);

        $this->actingAs($vendor, 'sanctum')
            ->getJson('/api/sales?seller_id='.$otherVendor->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownSale->id);

        $this->actingAs($vendor, 'sanctum')
            ->getJson('/api/sales/'.$otherSale->id)
            ->assertNotFound();
    }

    public function test_registering_a_sale_calculates_tax_and_decreases_stock(): void
    {
        $vendor = User::factory()->create(['role' => 'vendedor']);
        $branch = Branch::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'price' => '100.00',
            'cost' => '60.00',
            'stock' => 10,
            'active' => true,
        ]);

        $this->actingAs($vendor, 'sanctum')
            ->postJson('/api/sales', [
                'branch_id' => $branch->id,
                'customer_name' => 'Cliente de prueba',
                'payment_method' => 'efectivo',
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 2],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '200.00')
            ->assertJsonPath('data.tax', '32.00')
            ->assertJsonPath('data.total', '232.00')
            ->assertJsonPath('data.status', 'pagada');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 8,
        ]);

        $this->assertDatabaseHas('sale_items', [
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => '100.00',
            'line_total' => '200.00',
        ]);
    }

    public function test_only_admin_can_cancel_and_cancel_returns_stock(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $vendor = User::factory()->create(['role' => 'vendedor']);
        $branch = Branch::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'price' => '60.00',
            'cost' => '30.00',
            'stock' => 6,
        ]);

        $sale = Sale::factory()->create([
            'user_id' => $vendor->id,
            'branch_id' => $branch->id,
            'status' => 'pagada',
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 4,
            'unit_price' => $product->price,
            'line_total' => '240.00',
        ]);

        $this->actingAs($vendor, 'sanctum')
            ->postJson('/api/sales/'.$sale->id.'/cancel')
            ->assertForbidden();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 6,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/sales/'.$sale->id.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelada');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 10,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/sales/'.$sale->id.'/cancel')
            ->assertStatus(409);
    }
    public function test_admin_can_filter_sales_by_date_branch_seller_and_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $vendor = User::factory()->create(['role' => 'vendedor']);
        $otherVendor = User::factory()->create(['role' => 'vendedor']);
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();

        $matchingSale = Sale::factory()->create([
            'user_id' => $vendor->id,
            'branch_id' => $branch->id,
            'status' => 'pagada',
            'sold_at' => '2026-05-10 12:00:00',
        ]);

        Sale::factory()->create([
            'user_id' => $otherVendor->id,
            'branch_id' => $otherBranch->id,
            'status' => 'cancelada',
            'sold_at' => '2026-05-10 12:00:00',
        ]);

        Sale::factory()->create([
            'user_id' => $vendor->id,
            'branch_id' => $branch->id,
            'status' => 'pagada',
            'sold_at' => '2026-06-10 12:00:00',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/sales?date_from=2026-05-01&date_to=2026-05-31&branch_id='.$branch->id.'&seller_id='.$vendor->id.'&status=pagada')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matchingSale->id);
    }

}
