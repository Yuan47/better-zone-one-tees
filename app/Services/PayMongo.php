<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class PayMongo
{
    public function ready(): bool
    {
        $mode = config('shop.paymongo.mode');

        return in_array($mode, ['test', 'live'], true)
            && str_starts_with((string) config('shop.paymongo.secret'), $mode === 'live' ? 'sk_live_' : 'sk_test_')
            && filled(config('shop.paymongo.webhook_secret'))
            && ($mode === 'test' || (! config('shop.demo') && str_starts_with(config('app.url'), 'https://')));
    }

    public function create(Order $order, ?PaymentAttempt $attempt = null): array
    {
        if (! $this->ready()) {
            throw ValidationException::withMessages(['payment' => 'PayMongo test checkout is not configured yet. Your cart has been saved.']);
        }
        $lines = $order->items->map(fn ($i) => ['name' => $i->name.' / '.$i->size.' / '.$i->color, 'amount' => $i->unit_price, 'quantity' => $i->quantity, 'currency' => 'PHP'])->all();
        if ($order->discount) {
            $lines = [['name' => 'Clothing · '.$order->quantity.' pieces · Bulk 5% discount applied', 'amount' => $order->subtotal, 'quantity' => 1, 'currency' => 'PHP']];
        }
        if ($order->shipping) {
            $lines[] = ['name' => 'Lalamove delivery', 'amount' => $order->shipping, 'quantity' => 1, 'currency' => 'PHP'];
        }
        if ($attempt && ($attempt->amount !== $order->total || $order->amount_paid > 0)) {
            $lines = [['name' => 'Invoice '.$order->number.' · partial/balance payment', 'amount' => $attempt->amount, 'quantity' => 1, 'currency' => 'PHP']];
        }
        $billing = array_filter(['name' => $order->recipient, 'email' => $order->user?->email, 'phone' => $order->phone], fn ($value) => filled($value));
        $returnUrl = $order->user_id ? route('orders.show', $order) : URL::temporarySignedRoute('guest.orders.show', now()->addDays(30), ['order' => $order->id]);
        $response = Http::withBasicAuth(config('shop.paymongo.secret'), '')->connectTimeout(5)->timeout(25)
            ->post('https://api.paymongo.com/v2/checkout_sessions', ['data' => ['attributes' => [
                'line_items' => $lines, 'payment_method_types' => array_values(config('shop.paymongo.methods')),
                'reference_number' => $order->number, 'description' => 'Zone One Tee’s '.$order->number,
                'billing' => $billing,
                'success_url' => $returnUrl, 'cancel_url' => $returnUrl,
                'send_email_receipt' => filled($order->user?->email), 'pass_on_fees' => false, 'metadata' => array_filter(['order_number' => $order->number, 'invoice_payment_reference' => $attempt?->reference]),
            ]]])->throw()->json('data');
        $url = data_get($response, 'attributes.checkout_url');
        if (! is_string($url) || ! str_starts_with($url, 'https://checkout.paymongo.com/') || empty($response['id'])) {
            throw new \RuntimeException('Invalid PayMongo checkout response');
        }

        return ['checkout_session_id' => $response['id'], 'checkout_url' => $url];
    }

    public function verify(string $body, string $header): bool
    {
        if (! $this->ready()) {
            return false;
        }
        $parts = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }
        $time = $parts['t'] ?? '';
        $signature = $parts[config('shop.paymongo.mode') === 'live' ? 'li' : 'te'] ?? '';
        if (! ctype_digit($time) || abs(time() - (int) $time) > 300 || ! preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $time.'.'.$body, config('shop.paymongo.webhook_secret')), $signature);
    }

    public function retrieve(string $id): array
    {
        $this->assertSession($id);
        $data = Http::withBasicAuth(config('shop.paymongo.secret'), '')->connectTimeout(5)->timeout(20)
            ->get('https://api.paymongo.com/v1/checkout_sessions/'.$id)->throw()->json('data');
        if (! is_array($data)) {
            throw new \RuntimeException('Invalid payment response');
        }

        return $data;
    }

    public function expire(string $id): void
    {
        $this->assertSession($id);
        Http::withBasicAuth(config('shop.paymongo.secret'), '')->connectTimeout(5)->timeout(20)
            ->post('https://api.paymongo.com/v1/checkout_sessions/'.$id.'/expire')->throw();
    }

    private function assertSession(string $id): void
    {
        if (! $this->ready() || ! preg_match('/^cs_[A-Za-z0-9_]{1,120}$/', $id)) {
            throw ValidationException::withMessages(['payment' => 'A configured PayMongo account and valid checkout reference are required.']);
        }
    }
}
