<?php

use App\Models\Order;
use App\Services\Lalamove;
use App\Services\OrderFlow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('shop:sync-deliveries', function () {
    $client = app(Lalamove::class);
    if (! $client->ready()) {
        $this->warn('Lalamove is not configured.');

        return;
    }
    Order::where('status', 'out_for_delivery')->whereNotNull('delivery_id')->each(function ($order) use ($client) {
        try {
            $data = $client->track($order->delivery_id);
            if (($data['status'] ?? null) === 'COMPLETED') {
                $order->update(['status' => 'completed']);
            }
            if (in_array($data['status'] ?? null, ['CANCELED', 'REJECTED', 'EXPIRED'])) {
                $order->update(['status' => 'delivery_attention']);
            }
        } catch (Throwable $e) {
            $this->warn($order->number.': delivery update unavailable.');
        }
    });
});
Schedule::command('shop:sync-deliveries')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('shop:release-reservation {number} {--confirmed-no-payment}', function () {
    if (! $this->option('confirmed-no-payment')) {
        $this->error('First confirm in PayMongo that no payment/session exists, then supply --confirmed-no-payment.');

        return 1;
    }
    $order = Order::where('number', $this->argument('number'))->firstOrFail();
    app(OrderFlow::class)->cancel($order);
    $this->info('Unpaid reservation released.');
});
