<?php

namespace App\Providers;

use App\Services\InventoryWarnings;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as RenderedView;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(InventoryWarnings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('layout', function (RenderedView $view) {
            $request = request();
            if ($request->user()?->role !== 'admin'
                || ! (($request->routeIs('admin.*') && ! $request->routeIs('admin.login', 'admin.login.submit'))
                    || $request->routeIs('account*', 'orders.show'))) {
                return;
            }

            $data = $view->getData();
            $view->with('inventoryNotifications', $data['inventoryWarnings'] ?? app(InventoryWarnings::class)->counts());
        });
    }
}
