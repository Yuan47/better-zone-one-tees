<?php

namespace Tests\Feature;

use App\Exceptions\DeliveryBookingNotStarted;
use App\Models\Order;
use App\Services\Lalamove;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LalamovePhoneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['shop.lalamove.mode' => 'sandbox', 'shop.lalamove.key' => 'pk_test_fixture',
            'shop.lalamove.secret' => 'sk_test_fixture', 'shop.lalamove.address' => 'Sample pickup',
            'shop.lalamove.lat' => '14.5', 'shop.lalamove.lng' => '121', 'shop.lalamove.phone' => '09123456789']);
    }

    public static function phoneFormats(): array
    {
        return [['09123456780', '+639123456780'], ['9123456780', '+639123456780'],
            ['639123456780', '+639123456780'], ['+639123456780', '+639123456780'],
            ['+65 8123 4567', '+6581234567']];
    }

    #[DataProvider('phoneFormats')]
    public function test_booking_sends_international_contacts_without_rewriting_invoice_phone(string $phone, string $expected): void
    {
        $order = new Order(['phone' => $phone, 'recipient' => 'Sample recipient', 'address' => 'Sample delivery',
            'lat' => '14.6', 'lng' => '121.1', 'shipping' => 10000, 'number' => 'SAMPLE-PHONE']);
        Http::fake(['*/v3/quotations' => Http::response(['data' => ['quotationId' => 'quote_fixture',
            'expiresAt' => now()->addMinutes(5)->toIso8601String(), 'priceBreakdown' => ['currency' => 'PHP', 'total' => '100'],
            'stops' => [['stopId' => 'pickup'], ['stopId' => 'destination']]]]),
            '*/v3/orders' => Http::response(['data' => ['orderId' => '1234567890123456789']], 201)]);
        $result = app(Lalamove::class)->book($order);
        $this->assertSame('1234567890123456789', $result['orderId']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v3/orders')
            && $request['data']['sender']['phone'] === '+639123456789'
            && $request['data']['recipients'][0]['phone'] === $expected);
        Http::assertSentCount(2);
        $this->assertSame($phone, $order->phone);
    }

    public static function invalidContacts(): array
    {
        return [['sender', 'not-a-number'], ['recipient', '123'], ['recipient', '+0000000000']];
    }

    #[DataProvider('invalidContacts')]
    public function test_invalid_contact_stops_before_any_quote_or_booking(string $side, string $phone): void
    {
        config(['shop.lalamove.phone' => $side === 'sender' ? $phone : '09123456789']);
        $order = new Order(['phone' => $side === 'recipient' ? $phone : '09123456780']);
        try {
            app(Lalamove::class)->book($order);
            $this->fail('An invalid contact must not reach the provider.');
        } catch (DeliveryBookingNotStarted $exception) {
            $this->assertStringContainsString('No delivery was booked', $exception->getMessage());
            Http::assertNothingSent();
        }
    }
}
