<?php

use App\Models\Order;
use App\Services\DeliveryRecovery;
use App\Services\Lalamove;
use App\Services\OrderFlow;
use App\Services\PaymentRecovery;
use App\Services\PayMongo;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('shop:sync-deliveries', function () {
    $client = app(Lalamove::class);
    if (! $client->ready()) {
        $this->warn('Lalamove is not configured.');

        return;
    }
    Order::whereIn('status', ['out_for_delivery', 'delivery_attention'])->whereNull('provider_completed_at')->whereNotNull('delivery_id')->each(function ($order) {
        try {
            app(DeliveryRecovery::class)->refresh($order);
        } catch (Throwable $e) {
            $this->warn($order->number.': delivery update unavailable.');
        }
    });
});
Schedule::command('shop:sync-deliveries')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('shop:sync-payments', function () {
    if (! app(PayMongo::class)->ready()) {
        $this->warn('PayMongo is not configured.');

        return;
    }
    Order::where('payment_status', 'pending')->where('inventory_released', false)->whereNotNull('checkout_session_id')
        ->orderBy('id')->chunkById(50, function ($orders) {
            foreach ($orders as $order) {
                try {
                    app(PaymentRecovery::class)->check($order, $order->created_at->lte(now()->subMinutes(config('shop.unpaid_minutes'))));
                } catch (Throwable $e) {
                    $this->warn($order->number.': payment verification unavailable; reservation retained.');
                }
            }
        });
});
Schedule::command('shop:sync-payments')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('shop:release-reservation {number} {--confirmed-no-payment}', function () {
    if (! $this->option('confirmed-no-payment')) {
        $this->error('First confirm in PayMongo that no payment/session exists, then supply --confirmed-no-payment.');

        return 1;
    }
    $order = Order::where('number', $this->argument('number'))->firstOrFail();
    app(OrderFlow::class)->cancel($order);
    $this->info('Unpaid reservation released.');
});
