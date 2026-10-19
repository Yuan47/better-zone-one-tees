<?php

namespace App\Services;

use App\Exceptions\DeliveryBookingNotStarted;
use App\Models\Order;
use Carbon\CarbonImmutable;
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
        $senderPhone = self::internationalPhone((string) config('shop.lalamove.phone'));
        $recipientPhone = self::internationalPhone((string) $order->phone);
        if ($senderPhone === null || $recipientPhone === null) {
            $label = $senderPhone === null ? 'store pickup' : 'customer delivery';
            throw new DeliveryBookingNotStarted('The '.$label.' phone number needs a Philippine mobile number (09… or +639…) or an international number with +country code. No delivery was booked.');
        }
        // Quotes expire quickly; re-quote when packing is complete.
        try {
            $quote = $this->quote($order->address, $order->lat, $order->lng);
        } catch (\Throwable $exception) {
            throw new DeliveryBookingNotStarted('Lalamove could not prepare the delivery quotation. No delivery was booked; this order remains ready.', 0, $exception);
        }
        $id = $quote['quotationId'] ?? null;
        $stops = $quote['stops'] ?? null;
        $expires = $quote['expiresAt'] ?? null;
        $validStops = is_array($stops) && count($stops) === 2;
        foreach ([0, 1] as $index) {
            $stopId = $stops[$index]['stopId'] ?? null;
            $validStops = $validStops && (is_string($stopId) || is_int($stopId)) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', (string) $stopId);
        }
        $validExpiry = is_string($expires) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D', $expires);
        if ($validExpiry) {
            try {
                $validExpiry = CarbonImmutable::parse($expires)->isFuture();
            } catch (\Throwable $exception) {
                $validExpiry = false;
            }
        }
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $id) || ! $validStops || ! $validExpiry
            || (string) $stops[0]['stopId'] === (string) $stops[1]['stopId']) {
            throw ValidationException::withMessages(['delivery' => 'The delivery quotation is incomplete or expired. No delivery was booked; this order remains ready. Request a fresh quote before trying again.']);
        }
        if (data_get($quote, 'priceBreakdown.currency') !== 'PHP' || ! is_numeric(data_get($quote, 'priceBreakdown.total'))) {
            throw ValidationException::withMessages(['delivery' => 'The delivery quote is invalid.']);
        }
        $total = (float) data_get($quote, 'priceBreakdown.total');
        if (! is_finite($total) || $total < 0 || $total > PHP_INT_MAX / 100) {
            throw ValidationException::withMessages(['delivery' => 'The delivery quote amount is invalid. No delivery was booked; this order remains ready.']);
        }
        $cost = (int) round($total * 100);
        if ($cost > $order->shipping) {
            throw ValidationException::withMessages(['delivery' => 'The current delivery quote is higher than the delivery fee paid. No delivery was booked; this order remains ready. Review the delivery cost before trying again.']);
        }

        return $this->call('POST', '/v3/orders', [
            'quotationId' => $quote['quotationId'],
            'sender' => ['stopId' => $quote['stops'][0]['stopId'], 'name' => 'Zone One Tee’s', 'phone' => $senderPhone],
            'recipients' => [['stopId' => $quote['stops'][1]['stopId'], 'name' => $order->recipient, 'phone' => $recipientPhone]],
            'isPODEnabled' => true, 'metadata' => ['order_number' => $order->number],
        ]);
    }

    public static function internationalPhone(string $phone): ?string
    {
        $value = preg_replace('/[\s().-]+/u', '', trim($phone));
        if (is_string($value) && preg_match('/^(?:\+?63|0)?(9[0-9]{9})$/D', $value, $matches)) {
            return '+63'.$matches[1];
        }
        if (is_string($value) && preg_match('/^\+[1-9][0-9]{7,14}$/D', $value)) {
            return $value;
        }

        return null;
    }

    public function track(string $id): array
    {
        return $this->call('GET', '/v3/orders/'.rawurlencode($id));
    }
}
