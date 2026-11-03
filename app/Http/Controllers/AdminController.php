<?php

namespace App\Http\Controllers;

use App\Exceptions\DeliveryBookingNotStarted;
use App\Models\ChatbotFaq;
use App\Models\Order;
use App\Models\PaymentReminder;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\ActivityRecorder;
use App\Services\DeliveryRecovery;
use App\Services\EmailVerificationCodes;
use App\Services\ImageUploads;
use App\Services\InvoiceReminders;
use App\Services\Lalamove;
use App\Services\PayMongo;
use App\Services\ReadyOrderNotifications;
use App\Services\SalesReport;
use App\Services\Sku;
use App\Services\StoreKnowledge;
use App\Support\WorkspaceTableSort;
use Carbon\CarbonImmutable;
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
        $color = $r->input('color');
        if (is_string($color)) {
            $color = trim($color);
            $color = strcasecmp($color, 'white') === 0 ? 'White' : $color;
        }
        $size = $r->input('size');
        $r->merge(['size' => is_string($size) ? strtoupper(trim($size)) : $size, 'color' => $color]);
        $data = $r->validate([
            'size' => 'required|string|max:40',
            'color' => ['required', 'string', 'max:80', Rule::unique('variants', 'color')->where(fn ($q) => $q->where('product_id', $product->id)->where('size', $r->input('size')))->ignore($variant?->id)],
            'stock' => 'required|integer|min:0|max:1000000', 'reorder_level' => 'required|integer|min:0|max:1000000',
            'expected_stock' => [Rule::requiredIf($variant !== null), 'nullable', 'integer', 'min:0', 'max:1000000'],
            'retail' => 'required|numeric|min:1|max:100000', 'wholesale' => 'required|numeric|min:1|max:100000', 'reseller' => 'required|numeric|min:1|max:100000',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120', 'remove_photo' => 'nullable|boolean',
        ], ['expected_stock.required' => 'This stock form is out of date. Reopen the variant before saving.']);
        $data['color_group'] = $data['color'] === 'White' ? 'WHITE' : 'COLORED';
        foreach (['retail', 'wholesale', 'reseller'] as $tier) {
            $data[$tier.'_price'] = (int) round($data[$tier] * 100);
            unset($data[$tier]);
        }
        if ($data['wholesale_price'] > $data['retail_price'] || $data['reseller_price'] > $data['wholesale_price']) {
            throw ValidationException::withMessages(['prices' => 'Prices must stay the same or decrease as quantity increases.']);
        }
        unset($data['photo'],$data['remove_photo'],$data['expected_stock']);
        $oldPhoto = $variant?->image_path;
        $savedVariant = null;
        DB::transaction(function () use ($r, $product, $variant, $uploads, &$data, &$savedVariant) {
            Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($product->variants()->where('size', $data['size'])->whereRaw('LOWER(color) = ?', [mb_strtolower($data['color'])])
                ->when($variant, fn ($query) => $query->whereKeyNot($variant->id))->exists()) {
                throw ValidationException::withMessages(['color' => 'This size and color already exist. Edit the existing variant.']);
            }
            $locked = $variant ? Variant::whereKey($variant->id)->lockForUpdate()->firstOrFail() : null;
            if ($locked && $r->integer('expected_stock') !== (int) $locked->stock) {
                throw ValidationException::withMessages(['stock' => 'Stock changed while this page was open. The current count is '.$locked->stock.'. Review the refreshed count before saving again.']);
            }
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
        $data = $r->validate(['color' => 'required|string|max:80', 'template_color' => 'nullable|string|max:80', 'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120']);
        $color = trim($data['color']);
        $color = strcasecmp($color, 'white') === 0 ? 'White' : $color;
        $photo = null;
        $templates = collect();
        try {
            DB::transaction(function () use ($r, $data, $color, $product, $uploads, &$photo, &$templates) {
                Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
                if ($product->variants()->whereRaw('LOWER(color) = ?', [mb_strtolower($color)])->exists()) {
                    throw ValidationException::withMessages(['color' => 'This color already exists. Edit its variants below.']);
                }
                $colors = $product->variants()->orderBy('id')->pluck('color')->unique()->values();
                $source = filled($data['template_color'] ?? null) ? trim($data['template_color'])
                    : ($colors->first(fn ($name) => strcasecmp($name, 'white') !== 0) ?? $colors->first());
                $templates = $product->variants()->whereRaw('LOWER(color) = ?', [mb_strtolower($source ?? '')])->orderBy('id')->get()->unique('size');
                if ($templates->isEmpty()) {
                    throw ValidationException::withMessages(['template_color' => 'Choose an existing color to copy, or add the first size and color variant below.']);
                }
                $photo = $r->file('photo') ? $uploads->store($r->file('photo'), 'variants') : null;
                foreach ($templates as $v) {
                    $product->variants()->create([
                        'sku' => Sku::forNewVariant($product, $v->size, $color), 'size' => $v->size, 'color' => $color,
                        'color_group' => $color === 'White' ? 'WHITE' : 'COLORED', 'image_path' => $photo,
                        'retail_price' => $v->retail_price, 'wholesale_price' => $v->wholesale_price, 'reseller_price' => $v->reseller_price, 'stock' => 0, 'reorder_level' => $v->reorder_level,
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            if ($photo) {
                $uploads->delete($photo);
            }
            throw $exception;
        }
        ActivityRecorder::mark($r, 'catalog.color_added', $product, ['variant_count' => $templates->count()]);

        return back()->with('notice', 'Color added with the selected color’s sizes and prices. Review its prices and enter counted stock.');
    }

    public function orders(Request $r)
    {
        $now = CarbonImmutable::now('Asia/Manila');
        $today = $now->toDateString();
        $filters = $r->validate(['status' => 'nullable|in:awaiting_payment,to_pack,packing,ready,delivery_booking,out_for_delivery,completed,delivery_attention,cancelled,other',
            'payment_status' => 'nullable|in:paid,pending,partially_paid', 'delivery_method' => 'nullable|in:pickup,lalamove',
            'sales_channel' => 'nullable|in:online,walk_in', 'overdue' => 'nullable|boolean', 'outstanding' => 'nullable|boolean', 'exclude_cancelled' => 'nullable|boolean',
            'fulfillment' => 'nullable|in:packing,ready,courier,delivered', 'q' => 'nullable|string|max:100',
            'start_date' => 'nullable|required_with:end_date|date_format:Y-m-d|before_or_equal:'.$today,
            'end_date' => 'nullable|required_with:start_date|date_format:Y-m-d|after_or_equal:start_date|before_or_equal:'.$today]);
        if (! empty($filters['start_date']) && CarbonImmutable::parse($filters['start_date'])->lt(CarbonImmutable::parse($filters['end_date'])->subYears(10))) {
            throw ValidationException::withMessages(['end_date' => 'Choose a range of up to 10 years.']);
        }
        $orders = Order::with('user')->where('created_at', '<=', $now->toDateTimeString())
            ->when($filters['exclude_cancelled'] ?? false, fn ($q) => $q->where('status', '!=', 'cancelled')->where('inventory_released', false))
            ->when($filters['fulfillment'] ?? null, fn ($q, $v) => $q->whereDeliveryStage($v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $v === 'other'
                ? $q->whereNotIn('status', ['to_pack', 'packing', 'ready', 'delivery_booking', 'delivery_attention', 'out_for_delivery', 'completed']) : $q->where('status', $v))
            ->when($filters['payment_status'] ?? null, fn ($q, $v) => $q->where('payment_status', $v))
            ->when($filters['delivery_method'] ?? null, fn ($q, $v) => $q->where('delivery_method', $v))
            ->when($filters['sales_channel'] ?? null, fn ($q, $v) => $q->where('sales_channel', $v))
            ->when(($filters['outstanding'] ?? false) || ($filters['overdue'] ?? false), fn ($q) => $q
                ->whereIn('payment_status', ['pending', 'partially_paid'])->where('status', '!=', 'cancelled')->whereColumn('total', '>', 'amount_paid'))
            ->when($filters['overdue'] ?? false, fn ($q) => $q->where('due_at', '<', $now->toDateTimeString()))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($query) => $query->where('number', 'like', '%'.$v.'%')
                ->orWhere('recipient', 'like', '%'.$v.'%')->orWhereHas('user', fn ($user) => $user->where('name', 'like', '%'.$v.'%'))))
            ->when($filters['start_date'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00'))
            ->when($filters['end_date'] ?? null, fn ($q, $v) => $q->where('created_at', '<', CarbonImmutable::parse($v)->addDay()->toDateTimeString()));
        $received = "CASE WHEN orders.payment_status = 'paid' THEN orders.total ELSE orders.amount_paid END";
        $balance = "CASE WHEN orders.status = 'cancelled' OR orders.inventory_released = 1 THEN 0 WHEN orders.total > ($received) THEN orders.total - ($received) ELSE 0 END";
        $fulfillment = "CASE WHEN orders.status = 'cancelled' OR orders.inventory_released = 1 THEN 'Closed invoice'
            WHEN orders.payment_status != 'paid' THEN 'Awaiting full payment'
            WHEN orders.status = 'completed' AND (orders.customer_confirmed_at IS NOT NULL OR (orders.sales_channel = 'walk_in' AND orders.walk_in_received_at IS NOT NULL)) THEN 'Delivered'
            WHEN orders.status IN ('to_pack', 'packing') THEN 'Packing'
            WHEN orders.status = 'out_for_delivery' OR (orders.status = 'completed' AND orders.delivery_method = 'lalamove') THEN 'With courier'
            ELSE 'Ready for delivery' END";
        $orders = WorkspaceTableSort::apply($orders, $r, [
            'number' => 'orders.number', 'customer' => 'COALESCE((SELECT users.name FROM users WHERE users.id = orders.user_id), orders.recipient)',
            'quantity' => 'orders.quantity', 'total' => 'orders.total', 'received' => $received, 'outstanding' => $balance,
            'payment' => "REPLACE(orders.payment_status, '_', ' ')", 'fulfillment' => $fulfillment,
        ], fn ($query) => $query->latest())->paginate(20)->withQueryString();

        return view('admin.orders', compact('orders'));
    }

    public function status(Request $r, Order $order, ActivityRecorder $audit, ReadyOrderNotifications $notifications)
    {
        $data = $r->validate(['status' => 'required|in:packing,ready', 'reason' => 'required|string|min:10|max:1000']);
        $previousStatus = null;
        DB::transaction(function () use ($r, $order, $data, $audit, $notifications, &$previousStatus) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->fulfillmentOnHold()) {
                throw ValidationException::withMessages(['refund' => 'Review the approved, processing or fully completed refund before advancing fulfillment.']);
            }
            $allowed = ['to_pack' => ['packing', 'ready'], 'packing' => ['ready']];
            abort_unless($locked->payment_status === 'paid' && in_array($data['status'], $allowed[$locked->status] ?? []), 422);
            $previousStatus = $locked->status;
            $locked->update(['status' => $data['status']]);
            if (! $audit->record($r->user(), 'order.status_changed', $locked, ['previous_status' => $previousStatus, 'status' => $data['status'], 'reason' => $data['reason']])) {
                throw new \RuntimeException('The order change could not be audited.');
            }
            if ($data['status'] === 'ready') {
                $notifications->enqueue($locked);
            }
        });

        $email = $data['status'] === 'ready' ? $notifications->send($order->id) : null;

        return back()->with('notice', 'Order updated.'.match ($email) {
            'sent' => ' Brevo accepted the customer readiness email.',
            'failed' => ' Customer email was rejected. Check its email panel before retrying.',
            'unknown', 'review' => ' Customer email needs review. Do not resend until its outcome is confirmed.',
            'unavailable' => ' Customer email sending is disabled or not configured.',
            default => '',
        });
    }

    public function book(Request $r, Order $order, Lalamove $delivery, DeliveryRecovery $recovery)
    {
        if (! $delivery->ready()) {
            return back()->withErrors(['delivery' => 'Configure Lalamove sandbox credentials and pickup location first.']);
        }
        // Invalid or repeated bookings fail before any provider request or state change.
        $costLimit = null;
        $costReason = null;
        if ($r->hasAny(['courier_cost_limit', 'courier_cost_reason', 'cover_courier_difference'])) {
            abort_unless($r->user()->role === 'admin', 403);
            $approval = $r->validate([
                'courier_cost_limit' => ['required', 'regex:/^[0-9]{1,8}(?:\.[0-9]{1,2})?$/D', 'numeric', 'min:0', 'max:42949672.95'],
                'courier_cost_reason' => 'required|string|min:10|max:1000',
                'cover_courier_difference' => 'required|accepted',
            ]);
            $parts = explode('.', (string) $approval['courier_cost_limit']);
            $costLimit = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
            $costReason = $approval['courier_cost_reason'];
        }
        $claimed = $recovery->beginBooking($order, $costLimit === null ? null : $r->user(), $costLimit, $costReason);
        try {
            $result = $delivery->book($claimed);
        } catch (DeliveryBookingNotStarted $e) {
            $recovery->rejectBooking($claimed);

            return back()->withErrors(['delivery' => $e->getMessage()]);
        } catch (ValidationException $e) {
            // Lalamove validates the fresh quotation before sending the booking request.
            $recovery->rejectBooking($claimed);
            throw $e;
        } catch (\Throwable $e) {
            ActivityRecorder::mark($r, 'order.delivery_reconciliation_required', $order, ['status' => 'delivery_booking']);

            return back()->withErrors(['delivery' => 'Booking outcome needs checking in Lalamove before retrying. Staff should reconcile this order.']);
        }
        try {
            $settled = $recovery->acceptBooking($claimed, $result);
        } catch (\Throwable $e) {
            // A malformed success response or database failure may still mean a delivery exists.
            ActivityRecorder::mark($r, 'order.delivery_reconciliation_required', $order, ['status' => 'delivery_booking']);

            return back()->withErrors(['delivery' => 'Lalamove returned an incomplete booking result. Check its delivery ID and recover the original booking before retrying.']);
        }
        ActivityRecorder::mark($r, 'order.delivery_booked', $order, ['status' => $settled['status'], 'amount' => $result['_zone_one_quoted_cost'] ?? null]);

        return back()->with('notice', $settled['status'] === 'ready' ? 'The delivery ended at Lalamove. Its history was saved and this order is ready for another booking.' : 'Delivery booked.');
    }

    public function customers(Request $r)
    {
        $customers = WorkspaceTableSort::apply(User::where('role', 'customer'), $r, [
            'name' => 'users.name', 'email' => 'users.email', 'joined' => 'users.created_at',
        ], fn ($query) => $query->latest())->paginate(20)->withQueryString();

        return view('admin.customers', compact('customers'));
    }

    public function export(Request $r)
    {
        $report = app(SalesReport::class);
        $filters = $report->filters($r->all());
        $asOf = CarbonImmutable::now('Asia/Manila');
        ActivityRecorder::mark($r, 'reports.exported', null, ['format' => 'csv']);

        return response()->streamDownload(function () use ($report, $filters, $asOf) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Order', 'Paid at', 'Pieces before returns', 'Pricing tier', 'Clothing base PHP', 'Bulk discount PHP', 'Clothing after discounts before refunds PHP', 'Delivery PHP', 'Original invoice total PHP', 'Recipient', 'Sales channel', 'Confirmed refunds including delivery PHP', 'Net invoice including delivery PHP', 'Refunds known as of Asia/Manila'], ',', '"', '');
            $report->withInvoiceRefunds($report->paidOrders($filters['start_date'], $filters['end_date']), $asOf)->orderBy('id')->chunk(100, function ($orders) use ($out, $report, $asOf) {
                foreach ($orders as $o) {
                    $amounts = $report->invoiceAmounts($o);
                    $recipient = (string) $o->recipient;
                    if (preg_match('/^\s*[=+@-]/u', $recipient)) {
                        $recipient = "'".$recipient;
                    }
                    fputcsv($out, [$o->number, $o->paid_at?->toIso8601String(), $o->quantity, $o->tier,
                        number_format($o->merchandise_base / 100, 2, '.', ''), number_format($o->discount / 100, 2, '.', ''), number_format($o->subtotal / 100, 2, '.', ''),
                        number_format($o->shipping / 100, 2, '.', ''), number_format($o->total / 100, 2, '.', ''), $recipient,
                        $o->sales_channel === 'walk_in' ? 'Walk-in' : 'Online', number_format($amounts['confirmed_refunds'] / 100, 2, '.', ''),
                        number_format($amounts['net_invoice'] / 100, 2, '.', ''), $asOf->toIso8601String()], ',', '"', '');
                }
            });
            fclose($out);
        }, 'zone-one-sales-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function settings(Request $r, PayMongo $pay, Lalamove $delivery)
    {
        abort_unless($r->user()?->role === 'admin', 403);
        $search = is_string($r->query('faq_search')) ? mb_substr(trim($r->query('faq_search')), 0, 100) : '';
        $status = in_array($r->query('faq_status'), ['active', 'inactive'], true) ? $r->query('faq_status') : 'all';
        $faqs = ChatbotFaq::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('question', 'like', '%'.$search.'%')->orWhere('keywords', 'like', '%'.$search.'%')))
            ->when($status !== 'all', fn ($q) => $q->where('active', $status === 'active'))
            ->orderBy('sort_order')->orderBy('id')->paginate(12)->withQueryString();
        $reminders = app(InvoiceReminders::class);
        $emailVerification = app(EmailVerificationCodes::class);

        return view('admin.settings', [
            'faqs' => $faqs, 'faqSearch' => $search, 'faqStatus' => $status,
            'storeInfo' => app(StoreKnowledge::class)->info(),
            'payReady' => $pay->ready(), 'deliveryReady' => $delivery->ready(), 'groqReady' => filled(config('shop.groq.key')) && filled(config('shop.groq.model')),
            'reminderEnabled' => config('services.brevo.payment_reminders_enabled') === true, 'reminderConfigured' => $reminders->configured(),
            'reminderReviewCount' => PaymentReminder::whereIn('status', ['claimed', 'failed', 'unknown'])->count(),
            'reminderHistory' => PaymentReminder::with('order:id,number')->latest('id')->limit(12)->get(),
            'emailVerificationRequired' => $emailVerification->required(), 'emailVerificationConfigured' => $emailVerification->configured(),
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
        $changed = app(StoreKnowledge::class)->saveInfo($data);
        if ($changed !== []) {
            ActivityRecorder::mark($r, 'settings.updated', null, ['fields' => $changed]);
        }

        return back()->with('notice', $changed === [] ? 'No store information changes to save.' : 'Store information updated.');
    }
}
