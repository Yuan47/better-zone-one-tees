<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $role = 'admin'): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user);

        return $user;
    }

    private function product(array $attributes = []): Product
    {
        return Product::create($attributes + ['name' => 'Archive Tee', 'slug' => 'archive-tee-'.uniqid(), 'category' => '', 'description' => 'Plain clothing', 'active' => true]);
    }

    private function variant(Product $product, array $attributes = []): Variant
    {
        return $product->variants()->create($attributes + ['sku' => 'ARCHIVE-'.uniqid(), 'size' => 'M', 'color' => 'Red', 'color_group' => 'COLORED',
            'stock' => 20, 'reserved' => 0, 'reorder_level' => 3, 'retail_price' => 14000, 'wholesale_price' => 13000, 'reseller_price' => 12000]);
    }

    private function variantInput(array $attributes = []): array
    {
        return $attributes + ['size' => '2XL', 'color' => 'Blue', 'stock' => 10, 'reorder_level' => 3, 'retail' => 140, 'wholesale' => 130, 'reseller' => 120];
    }

    public function test_owner_archives_and_restores_product_without_changing_stock(): void
    {
        $this->owner();
        $product = $this->product();
        $variant = $this->variant($product);
        $this->post(route('admin.products.archive', $product), ['reason' => 'Paused for stock review'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($product->fresh()->archived_at);
        $this->assertFalse($product->fresh()->active);
        $this->assertSame(20, $variant->fresh()->stock);
        $this->get(route('admin.products'))->assertDontSee($product->name);
        $this->get(route('admin.products', ['archived' => 1]))->assertSee($product->name)->assertSee('Archived');
        $this->get(route('product', $product))->assertNotFound();
        $this->post(route('admin.products.restore', $product), ['reason' => 'Owner confirmed counted stock'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($product->fresh()->archived_at);
        $this->assertTrue($product->fresh()->active);
        $this->assertSame(20, $variant->fresh()->stock);
        $this->assertDatabaseHas('activity_logs', ['action' => 'product.archived', 'target_id' => $product->id]);
        $this->assertSame('Paused for stock review', ActivityLog::where('action', 'product.archived')->firstOrFail()->metadata['reason']);
    }

    public function test_restoring_a_previously_hidden_product_keeps_its_hidden_status(): void
    {
        $this->owner();
        $product = $this->product(['active' => false]);
        $this->post(route('admin.products.archive', $product), ['reason' => 'Archive hidden supplier style'])->assertSessionHasNoErrors();
        $this->post(route('admin.products.restore', $product), ['reason' => 'Restore draft for editing'])->assertSessionHasNoErrors();
        $this->assertFalse($product->fresh()->active);
    }

    public function test_permanent_product_delete_requires_archive_confirmation_and_reason(): void
    {
        $this->owner();
        $product = $this->product();
        $variant = $this->variant($product);
        $input = ['reason' => 'Duplicate unused catalog entry', 'confirm_delete' => 1];
        $this->delete(route('admin.products.destroy', $product), $input)->assertSessionHasErrors('catalog');
        $this->post(route('admin.products.archive', $product), ['reason' => 'Archive duplicate catalog entry'])->assertSessionHasNoErrors();
        $this->delete(route('admin.products.destroy', $product), ['reason' => $input['reason']])->assertSessionHasErrors('confirm_delete');
        $this->delete(route('admin.products.destroy', $product), $input)->assertRedirect(route('admin.products', ['archived' => 1]))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('variants', ['id' => $variant->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'product.deleted', 'target_id' => $product->id]);
    }

    public function test_product_with_order_history_stays_archived_and_preserves_order_items(): void
    {
        $this->owner();
        $product = $this->product();
        $variant = $this->variant($product);
        $order = app(OrderFlow::class)->reserve([$variant->id => 2], ['recipient' => 'Recorded buyer', 'phone' => '09123456789', 'address' => 'Store pickup', 'delivery_method' => 'pickup', 'shipping' => 0], User::factory()->create()->id);
        $item = $order->items()->firstOrFail();
        $snapshot = $item->getAttributes();
        $this->post(route('admin.products.archive', $product), ['reason' => 'Discontinued supplier style'])->assertSessionHasNoErrors();
        $this->delete(route('admin.products.destroy', $product), ['reason' => 'Remove discontinued style', 'confirm_delete' => 1])->assertSessionHasErrors('catalog');
        $this->assertNotNull($product->fresh()->archived_at);
        $this->assertSame($snapshot, $item->fresh()->getAttributes());
        $this->assertSame(20, $variant->fresh()->stock);
        $this->assertSame(2, $variant->fresh()->reserved);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_unreserved_historical_variant_is_also_protected_from_permanent_delete(): void
    {
        $this->owner();
        $product = $this->product();
        $variant = $this->variant($product);
        $order = app(OrderFlow::class)->reserve([$variant->id => 1], ['recipient' => 'Historical buyer', 'phone' => '09123456789', 'address' => 'Store pickup', 'delivery_method' => 'pickup', 'shipping' => 0], User::factory()->create()->id);
        $variant->update(['reserved' => 0]);
        $order->update(['status' => 'cancelled', 'inventory_released' => true]);
        $this->post(route('admin.variants.archive', [$product, $variant]), ['reason' => 'Archive previous supplier color'])->assertSessionHasNoErrors();
        $this->delete(route('admin.variants.destroy', [$product, $variant]), ['reason' => 'Delete supplier color', 'confirm_delete' => 1])->assertSessionHasErrors('catalog');
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'variant_id' => $variant->id]);
    }

    public function test_variant_archive_restore_and_permanent_delete_follow_required_order(): void
    {
        $this->owner();
        $product = $this->product();
        $variant = $this->variant($product);
        $this->delete(route('admin.variants.destroy', [$product, $variant]), ['reason' => 'Duplicate variant entry', 'confirm_delete' => 1])->assertSessionHasErrors('catalog');
        $this->post(route('admin.variants.archive', [$product, $variant]), ['reason' => 'Archive variant for review'])->assertSessionHasNoErrors();
        $this->assertSame(0, $variant->fresh()->available());
        $this->assertSame(20, $variant->fresh()->stock);
        $this->assertFalse(Variant::sellable()->whereKey($variant->id)->exists());
        $this->post(route('admin.variants.restore', [$product, $variant]), ['reason' => 'Restore counted variant'])->assertSessionHasNoErrors();
        $this->assertSame(20, $variant->fresh()->available());
        $this->post(route('admin.variants.archive', [$product, $variant]), ['reason' => 'Archive duplicate variant'])->assertSessionHasNoErrors();
        $this->delete(route('admin.variants.destroy', [$product, $variant]), ['reason' => 'Delete duplicate unused variant', 'confirm_delete' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('variants', ['id' => $variant->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_archived_variant_cannot_be_restored_under_archived_product(): void
    {
        $this->owner();
        $product = $this->product();
        $variant = $this->variant($product);
        $this->post(route('admin.variants.archive', [$product, $variant]), ['reason' => 'Archive previous color'])->assertSessionHasNoErrors();
        $this->post(route('admin.products.archive', $product), ['reason' => 'Archive previous shirt'])->assertSessionHasNoErrors();
        $this->post(route('admin.variants.restore', [$product, $variant]), ['reason' => 'Restore previous color'])->assertSessionHasErrors('catalog');
    }

    public function test_staff_customer_and_wrong_parent_cannot_manage_catalog_lifecycle(): void
    {
        $product = $this->product();
        $variant = $this->variant($product);
        foreach (['staff', 'customer'] as $role) {
            $this->owner($role);
            $this->post(route('admin.products.archive', $product), ['reason' => 'Unauthorized archive attempt'])->assertForbidden();
            $this->delete(route('admin.variants.destroy', [$product, $variant]), ['reason' => 'Unauthorized delete attempt', 'confirm_delete' => 1])->assertForbidden();
        }
        $this->owner();
        $other = $this->product();
        $this->post(route('admin.variants.archive', [$other, $variant]), ['reason' => 'Wrong parent archive attempt'])->assertNotFound();
        $this->assertNull($variant->fresh()->archived_at);
    }

    public function test_new_adult_variants_use_standard_sizes_and_existing_legacy_size_is_editable(): void
    {
        $this->owner();
        $product = $this->product();
        $this->post(route('admin.variants.store', $product), $this->variantInput())->assertSessionHasNoErrors();
        $this->assertDatabaseHas('variants', ['product_id' => $product->id, 'size' => '2XL', 'color' => 'Blue']);
        $this->post(route('admin.variants.store', $product), $this->variantInput(['size' => 'XXXL', 'color' => 'Yellow']))->assertSessionHasErrors('size');
        $legacy = $this->variant($product, ['size' => 'TN', 'color' => 'White']);
        $this->patch(route('admin.variants.save', [$product, $legacy]), $this->variantInput(['size' => 'TN', 'color' => 'White', 'stock' => 20, 'expected_stock' => 20]))->assertSessionHasNoErrors();
        $this->assertSame('TN', $legacy->fresh()->size);
        $this->get(route('admin.products.edit', $product))->assertOk()->assertSee('<select name="size" required>', false)->assertSee('5XL');
    }

    public function test_kids_numeric_sizes_are_retained_and_archived_variants_cannot_be_edited(): void
    {
        $this->owner();
        $product = $this->product(['name' => 'Kids Round Neck']);
        $this->post(route('admin.variants.store', $product), $this->variantInput(['size' => '#10']))->assertSessionHasNoErrors();
        $variant = $product->variants()->firstOrFail();
        $this->post(route('admin.variants.archive', [$product, $variant]), ['reason' => 'Archive old kids color'])->assertSessionHasNoErrors();
        $this->patch(route('admin.variants.save', [$product, $variant]), $this->variantInput(['size' => '#10', 'expected_stock' => 10]))->assertSessionHasErrors('catalog');
    }

    public function test_archived_variant_is_absent_from_store_choices_and_rejected_by_stale_carts(): void
    {
        $owner = $this->owner();
        $product = $this->product();
        $active = $this->variant($product);
        $archived = $this->variant($product, ['size' => '4XL', 'color' => 'Purple']);
        $this->post(route('admin.variants.archive', [$product, $archived]), ['reason' => 'Remove discontinued size color'])->assertSessionHasNoErrors();
        $this->get('/?color=Purple')->assertOk()->assertDontSee($product->name);
        $this->get(route('product', $product))->assertOk()->assertDontSee('"color":"Purple"', false)->assertSee('"color":"Red"', false);
        $this->post(route('cart.add'), ['variant_id' => $archived->id, 'quantity' => 1])->assertNotFound();
        $this->withSession(['cart' => [$archived->id => 1, $active->id => 2]])->get(route('cart'))
            ->assertOk()->assertSessionHas('cart', [$active->id => 2]);

        try {
            app(OrderFlow::class)->reserve([$archived->id => 1], ['recipient' => 'Stale cart buyer', 'phone' => '09123456789', 'address' => 'Store pickup', 'delivery_method' => 'pickup', 'shipping' => 0], $owner->id);
            $this->fail('Archived variant was reserved by a stale cart.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('cart', $exception->errors());
        }
        $this->assertSame(0, $archived->fresh()->reserved);
        $this->assertDatabaseCount('orders', 0);
    }
}
