<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
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

    public function create(Order $order): array
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
        $response = Http::withBasicAuth(config('shop.paymongo.secret'), '')->connectTimeout(5)->timeout(25)
            ->post('https://api.paymongo.com/v2/checkout_sessions', ['data' => ['attributes' => [
                'line_items' => $lines, 'payment_method_types' => array_values(config('shop.paymongo.methods')),
                'reference_number' => $order->number, 'description' => 'Zone One Tee’s '.$order->number,
                'billing' => ['name' => $order->recipient, 'email' => $order->user->email, 'phone' => $order->phone],
                'success_url' => route('orders.show', $order), 'cancel_url' => route('orders.show', $order),
                'send_email_receipt' => true, 'pass_on_fees' => false, 'metadata' => ['order_number' => $order->number],
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
}
