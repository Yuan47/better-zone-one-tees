<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Lalamove
{
    public function ready(): bool
    {
        foreach (['key', 'secret', 'address', 'lat', 'lng', 'phone'] as $key) {
            if (! filled(config('shop.lalamove.'.$key))) {
                return false;
            }
        }
        $sandbox = config('shop.lalamove.mode') === 'sandbox';
        $prefix = $sandbox ? '_test_' : '_prod_';

        return in_array(config('shop.lalamove.mode'), ['sandbox', 'production'], true)
            && str_contains(config('shop.lalamove.key'), $prefix) && str_contains(config('shop.lalamove.secret'), $prefix)
            && ($sandbox || ! config('shop.demo'));
    }

    private function call(string $method, string $path, ?array $data = null): array
    {
        if (! $this->ready()) {
            throw ValidationException::withMessages(['delivery' => 'Lalamove sandbox and store pickup details are not configured. Store pickup is available.']);
        }
        $body = $data ? json_encode(['data' => $data], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : '';
        $timestamp = (string) (int) floor(microtime(true) * 1000);
        $signature = hash_hmac('sha256', $timestamp."\r\n".$method."\r\n".$path."\r\n\r\n".$body, config('shop.lalamove.secret'));
        $base = config('shop.lalamove.mode') === 'sandbox' ? 'https://rest.sandbox.lalamove.com' : 'https://rest.lalamove.com';

        return Http::withHeaders(['Authorization' => 'hmac '.config('shop.lalamove.key').':'.$timestamp.':'.$signature, 'Market' => 'PH', 'Request-ID' => (string) Str::uuid()])
            ->connectTimeout(5)->timeout(25)->withBody($body, 'application/json')->send($method, $base.$path)->throw()->json('data');
    }

    public function quote(string $address, string $lat, string $lng): array
    {
        return $this->call('POST', '/v3/quotations', [
            'serviceType' => config('shop.lalamove.service'), 'language' => 'en_PH',
            'stops' => [
                ['coordinates' => ['lat' => (string) config('shop.lalamove.lat'), 'lng' => (string) config('shop.lalamove.lng')], 'address' => config('shop.lalamove.address')],
                ['coordinates' => ['lat' => $lat, 'lng' => $lng], 'address' => $address],
            ],
            'isRouteOptimized' => false,
        ]);
    }

    public function book(Order $order): array
    {
        // Quotes expire quickly; re-quote when packing is complete.
        $quote = $this->quote($order->address, $order->lat, $order->lng);
        if (data_get($quote, 'priceBreakdown.currency') !== 'PHP' || ! is_numeric(data_get($quote, 'priceBreakdown.total'))) {
            throw ValidationException::withMessages(['delivery' => 'The delivery quote is invalid.']);
        }
        $cost = (int) round((float) data_get($quote, 'priceBreakdown.total') * 100);
        if ($cost > $order->shipping) {
            throw ValidationException::withMessages(['delivery' => 'The new delivery quote exceeds the amount collected. Arrange the difference with the customer before booking.']);
        }

        return $this->call('POST', '/v3/orders', [
            'quotationId' => $quote['quotationId'],
            'sender' => ['stopId' => $quote['stops'][0]['stopId'], 'name' => 'Zone One Tee’s', 'phone' => config('shop.lalamove.phone')],
            'recipients' => [['stopId' => $quote['stops'][1]['stopId'], 'name' => $order->recipient, 'phone' => $order->phone]],
            'isPODEnabled' => true, 'metadata' => ['order_number' => $order->number],
        ]);
    }

    public function track(string $id): array
    {
        return $this->call('GET', '/v3/orders/'.rawurlencode($id));
    }
}
