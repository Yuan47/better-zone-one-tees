<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\ImageUploads;
use App\Services\ActivityRecorder;
use App\Services\Lalamove;
use App\Services\PayMongo;
use App\Services\Sku;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function products(Request $r)
    {
        $products = Product::with('variants')->when(! $r->boolean('hidden'), fn ($q) => $q->where('active', true))->when($r->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$r->string('q').'%'))->get();

        return view('admin.products', compact('products'));
    }

    public function edit(Request $r, Product $product)
    {
        $product->load('variants');

        return view('admin.product', ['product' => $product, 'editingVariant' => $product->variants->firstWhere('id', (int) $r->query('variant'))]);
    }

    public function createForm()
    {
        $product = new Product(['active' => true]);

        return view('admin.product', ['product' => $product, 'editingVariant' => null]);
    }

    public function save(Request $r, ImageUploads $uploads, ?Product $product = null)
    {
        $data = $r->validate([
            'name' => 'required|string|max:150', 'description' => 'required|string|max:2000', 'active' => 'required|boolean',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120', 'remove_photo' => 'nullable|boolean',
            'size_chart' => 'nullable|array|max:30', 'size_chart.*.size' => 'nullable|string|max:40',
            'size_chart.*.width' => 'nullable|numeric|min:1|max:100', 'size_chart.*.length' => 'nullable|numeric|min:1|max:100',
            'size_chart_note' => 'nullable|string|max:1000',
        ]);
        $chart = [];
        foreach ($data['size_chart'] ?? [] as $row) {
            $size = trim($row['size'] ?? '');
            if ($size === '') {
                if (filled($row['width'] ?? null) || filled($row['length'] ?? null)) {
                    throw ValidationException::withMessages(['size_chart' => 'Add a size label for each measurement.']);
                }

                continue;
            }
            if (in_array(strtoupper($size), array_column($chart, 'size'), true)) {
                throw ValidationException::withMessages(['size_chart' => 'Size chart labels must be unique.']);
            }
            $chart[] = ['size' => strtoupper($size), 'width' => isset($row['width']) ? (float) $row['width'] : null, 'length' => isset($row['length']) ? (float) $row['length'] : null];
        }
        $oldPhoto = $product?->image_path;
        $newPhoto = $r->file('photo') ? $uploads->store($r->file('photo'), 'products') : null;
        unset($data['photo'],$data['remove_photo']);
        $data['size_chart'] = $chart;
        if ($newPhoto) {
            $data['image_path'] = $newPhoto;
        } elseif ($r->boolean('remove_photo')) {
            $data['image_path'] = null;
        }
        if (! $product) {
            $product = Product::create($data + ['slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(5)), 'category' => '', 'art_color' => '#e4d9c8']);
        } else {
            $product->update($data);
        }
        if ($oldPhoto && ($newPhoto || $r->boolean('remove_photo'))) {
            $uploads->delete($oldPhoto);
        }
        $changedFields = array_values(array_diff(array_keys($product->wasRecentlyCreated ? $product->getAttributes() : $product->getChanges()), ['updated_at', 'created_at', 'id']));
        if ($product->wasRecentlyCreated || $changedFields) {
            ActivityRecorder::mark($r, $product->wasRecentlyCreated ? 'product.created' : 'product.updated', $product, ['fields' => $changedFields]);
        }

        return redirect()->route('admin.products.edit', $product)->with('notice', 'Product saved.');
    }

    public function variant(Request $r, Product $product, ImageUploads $uploads, ?Variant $variant = null)
    {
        if ($variant) {
            abort_unless($variant->product_id === $product->id, 404);
        }
        $group = $r->input('color_group', 'COLORED');
        $r->merge(['color_group' => $group, 'size' => strtoupper(trim((string) $r->input('size'))), 'color' => $group === 'WHITE' ? 'White' : trim((string) $r->input('color'))]);
        $data = $r->validate([
            'size' => 'required|string|max:40', 'color_group' => 'required|in:WHITE,COLORED',
            'color' => ['required', 'string', 'max:80', Rule::unique('variants', 'color')->where(fn ($q) => $q->where('product_id', $product->id)->where('size', $r->input('size')))->ignore($variant?->id)],
            'stock' => 'required|integer|min:0|max:1000000', 'reorder_level' => 'required|integer|min:0|max:1000000',
            'retail' => 'required|numeric|min:1|max:100000', 'wholesale' => 'required|numeric|min:1|max:100000', 'reseller' => 'required|numeric|min:1|max:100000',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120', 'remove_photo' => 'nullable|boolean',
        ]);
        if ($group === 'COLORED' && strtolower($data['color']) === 'white') {
            throw ValidationException::withMessages(['color' => 'Select WHITE for white clothing.']);
        }
        foreach (['retail', 'wholesale', 'reseller'] as $tier) {
            $data[$tier.'_price'] = (int) round($data[$tier] * 100);
            unset($data[$tier]);
        }
        if ($data['wholesale_price'] > $data['retail_price'] || $data['reseller_price'] > $data['wholesale_price']) {
            throw ValidationException::withMessages(['prices' => 'Prices must stay the same or decrease as quantity increases.']);
        }
        unset($data['photo'],$data['remove_photo']);
        $oldPhoto = $variant?->image_path;
        $savedVariant = null;
        DB::transaction(function () use ($r, $product, $variant, $uploads, &$data, &$savedVariant) {
            Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $locked = $variant ? Variant::whereKey($variant->id)->lockForUpdate()->firstOrFail() : null;
            if ($locked && $data['stock'] < $locked->reserved) {
                throw ValidationException::withMessages(['stock' => 'Some stock is committed to orders. On-hand stock cannot be reduced below those commitments.']);
            }
            if ($r->file('photo')) {
                $data['image_path'] = $uploads->store($r->file('photo'), 'variants');
            } elseif ($r->boolean('remove_photo')) {
                $data['image_path'] = null;
            }
            if ($locked) {
                $locked->update($data);
                $savedVariant = $locked;
            } else {
                $savedVariant = $product->variants()->create($data + ['sku' => Sku::forNewVariant($product, $data['size'], $data['color'])]);
            }
        });
        if ($oldPhoto && ($r->file('photo') || $r->boolean('remove_photo'))) {
            $uploads->delete($oldPhoto);
        }
        $changedFields = array_values(array_diff(array_keys($savedVariant->wasRecentlyCreated ? $savedVariant->getAttributes() : $savedVariant->getChanges()), ['updated_at', 'created_at', 'id']));
        if ($savedVariant->wasRecentlyCreated || $changedFields) {
            ActivityRecorder::mark($r, $savedVariant->wasRecentlyCreated ? 'variant.created' : 'variant.updated', $savedVariant, ['fields' => $changedFields]);
        }

        return back()->with('notice', 'Variant saved. SKU is assigned automatically.');
    }

    public function color(Request $r, Product $product, ImageUploads $uploads)
    {
        $data = $r->validate(['color' => 'required|string|max:80', 'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120']);
        $color = trim($data['color']);
        if (strtolower($color) === 'white' || $product->variants()->where('color', $color)->exists()) {
            throw ValidationException::withMessages(['color' => 'This color already exists. Edit its variants below.']);
        }
        $templates = $product->variants()->where('color_group', 'COLORED')->orderBy('id')->get()->unique('size');
        if ($templates->isEmpty()) {
            throw ValidationException::withMessages(['color' => 'Add a COLORED size variant first to set its prices.']);
        }
        $photo = $r->file('photo') ? $uploads->store($r->file('photo'), 'variants') : null;
        DB::transaction(function () use ($templates, $color, $product, $photo) {
            Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            foreach ($templates as $v) {
                $product->variants()->create([
                    'sku' => Sku::forNewVariant($product, $v->size, $color), 'size' => $v->size, 'color' => $color, 'color_group' => 'COLORED', 'image_path' => $photo,
                    'retail_price' => $v->retail_price, 'wholesale_price' => $v->wholesale_price, 'reseller_price' => $v->reseller_price, 'stock' => 0, 'reorder_level' => $v->reorder_level,
                ]);
            }
        });
        ActivityRecorder::mark($r, 'catalog.color_added', $product, ['variant_count' => $templates->count()]);

        return back()->with('notice', 'Color added across existing colored sizes. Review its prices and enter counted stock.');
    }

    public function orders(Request $r)
    {
        $orders = Order::with('user')->when($r->filled('status'), fn ($q) => $q->where('status', $r->string('status')))->latest()->paginate(20);

        return view('admin.orders', compact('orders'));
    }

    public function status(Request $r, Order $order)
    {
        $data = $r->validate(['status' => 'required|in:packing,ready,completed']);
        $previousStatus = null;
        DB::transaction(function () use ($order, $data, &$previousStatus) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $allowed = ['to_pack' => ['packing'], 'packing' => ['ready'], 'ready' => $locked->delivery_method === 'pickup' ? ['completed'] : []];
            abort_unless($locked->payment_status === 'paid' && in_array($data['status'], $allowed[$locked->status] ?? []), 422);
            $previousStatus = $locked->status;
            $locked->update($data);
        });
        ActivityRecorder::mark($r, 'order.status_changed', $order, ['previous_status' => $previousStatus, 'status' => $data['status']]);

        return back()->with('notice', 'Order updated.');
    }

    public function book(Request $r, Order $order, Lalamove $delivery)
    {
        if (! $delivery->ready()) {
            return back()->withErrors(['delivery' => 'Configure Lalamove sandbox credentials and pickup location first.']);
        }
        $bookingStarted = false;
        try {
            // Lock stops two staff members booking the same order concurrently.
            DB::transaction(function () use ($order, &$bookingStarted) {
                $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                abort_unless($order->status === 'ready' && $order->payment_status === 'paid' && $order->delivery_method === 'lalamove' && ! $order->delivery_id, 422);
                $order->update(['status' => 'delivery_booking']);
                $bookingStarted = true;
            });
            $result = $delivery->book($order);
            $order->update(['delivery_id' => $result['orderId'], 'tracking_url' => $result['shareLink'] ?? null, 'status' => 'out_for_delivery']);
            ActivityRecorder::mark($r, 'order.delivery_booked', $order, ['status' => 'out_for_delivery']);
        } catch (ValidationException $e) {
            $order->update(['status' => 'ready']);
            throw $e;
        } catch (\Throwable $e) {
            if ($bookingStarted && $order->fresh()->status === 'delivery_booking') {
                ActivityRecorder::mark($r, 'order.delivery_reconciliation_required', $order, ['status' => 'delivery_booking']);
            }
            return back()->withErrors(['delivery' => 'Booking outcome needs checking in Lalamove before retrying. Staff should reconcile this order.']);
        }

        return back()->with('notice', 'Delivery booked.');
    }

    public function customers()
    {
        $customers = User::where('role', 'customer')->latest()->paginate(20);

        return view('admin.customers', compact('customers'));
    }

    public function export(Request $r)
    {
        ActivityRecorder::mark($r, 'reports.exported', null, ['format' => 'csv']);
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Order', 'Paid at', 'Pieces', 'Pricing tier', 'Clothing base PHP', 'Bulk discount PHP', 'Net clothing PHP', 'Delivery PHP', 'Total PHP'], ',', '"', '');
            Order::where('payment_status', 'paid')->orderBy('id')->chunk(100, function ($orders) use ($out) {
                foreach ($orders as $o) {
                    fputcsv($out, [$o->number, $o->paid_at?->toIso8601String(), $o->quantity, $o->tier,
                        number_format($o->merchandise_base / 100, 2, '.', ''), number_format($o->discount / 100, 2, '.', ''), number_format($o->subtotal / 100, 2, '.', ''),
                        number_format($o->shipping / 100, 2, '.', ''), number_format($o->total / 100, 2, '.', '')], ',', '"', '');
                }
            });
            fclose($out);
        }, 'zone-one-sales-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function settings(Request $r, PayMongo $pay, Lalamove $delivery)
    {
        $search = is_string($r->query('faq_search')) ? mb_substr(trim($r->query('faq_search')), 0, 100) : '';
        $status = in_array($r->query('faq_status'), ['active', 'inactive'], true) ? $r->query('faq_status') : 'all';
        $faqs = \App\Models\ChatbotFaq::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('question', 'like', '%'.$search.'%')->orWhere('keywords', 'like', '%'.$search.'%')))
            ->when($status !== 'all', fn ($q) => $q->where('active', $status === 'active'))
            ->orderBy('sort_order')->orderBy('id')->paginate(12)->withQueryString();

        return view('admin.settings', [
            'faqs' => $faqs, 'faqSearch' => $search, 'faqStatus' => $status,
            'storeInfo' => app(\App\Services\StoreKnowledge::class)->info(),
            'payReady' => $pay->ready(), 'deliveryReady' => $delivery->ready(), 'grokReady' => filled(config('shop.grok.key')),
        ]);
    }

    public function saveSettings(Request $r)
    {
        $data = $r->validate([
            'opening_hours' => 'nullable|string|max:1000', 'pickup_address' => 'nullable|string|max:2000',
            'pickup_instructions' => 'nullable|string|max:2000', 'support_email' => 'nullable|email:rfc|max:254',
            'support_phone' => ['nullable', 'string', 'max:40', 'regex:/^[+0-9().\s-]{7,40}$/'],
            'store_note' => 'nullable|string|max:5000',
        ]);
        $changed = app(\App\Services\StoreKnowledge::class)->saveInfo($data);
        if ($changed !== []) {
            \App\Services\ActivityRecorder::mark($r, 'settings.updated', null, ['fields' => $changed]);
        }

        return back()->with('notice', $changed === [] ? 'No store information changes to save.' : 'Store information updated.');
    }
}
