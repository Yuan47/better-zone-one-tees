<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', [StoreController::class, 'index'])->name('store');
Route::get('/products/{product:slug}', [StoreController::class, 'product'])->name('product');
Route::get('/cart', [StoreController::class, 'cart'])->name('cart');
Route::post('/cart', [StoreController::class, 'add'])->middleware('throttle:60,1')->name('cart.add');
Route::patch('/cart/{variant}', [StoreController::class, 'update'])->name('cart.update');
Route::middleware('guest')->group(function () {
    Route::get('/login', fn () => app(AuthController::class)->form('login'))->name('login');
    Route::get('/register', fn () => app(AuthController::class)->form('register'))->name('register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::post('/chat', [ChatController::class, '__invoke'])->middleware('throttle:12,1')->name('chat');
Route::post('/webhooks/paymongo', WebhookController::class)->name('webhooks.paymongo');
Route::middleware('auth')->group(function () {
    Route::post('/wishlist/{product}', [StoreController::class, 'wishlist'])->name('wishlist.toggle');
    Route::get('/checkout', [StoreController::class, 'checkout'])->name('checkout');
    Route::post('/checkout/quote', [StoreController::class, 'quote'])->middleware('throttle:10,1')->name('checkout.quote');
    Route::post('/checkout', [StoreController::class, 'place'])->middleware('throttle:10,1')->block(40, 40)->name('checkout.place');
    Route::get('/orders', [StoreController::class, 'orders'])->name('orders');
    Route::get('/orders/{order}', [StoreController::class, 'order'])->name('orders.show');
});
Route::middleware(['auth', 'staff'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/orders', [AdminController::class, 'orders'])->name('orders');
    Route::get('/reports/export', [AdminController::class, 'export'])->name('reports.export');
    Route::patch('/orders/{order}', [AdminController::class, 'status'])->name('orders.status');
    Route::post('/orders/{order}/delivery', [AdminController::class, 'book'])->middleware('throttle:5,1')->name('orders.delivery');
    Route::middleware('staff:admin')->group(function () {
        Route::get('/products', [AdminController::class, 'products'])->name('products');
        Route::get('/products/create', [AdminController::class, 'createForm'])->name('products.create');
        Route::post('/products', [AdminController::class, 'save'])->name('products.store');
        Route::get('/products/{product}', [AdminController::class, 'edit'])->name('products.edit');
        Route::patch('/products/{product}', [AdminController::class, 'save'])->name('products.save');
        Route::post('/products/{product}/variants', [AdminController::class, 'variant'])->name('variants.store');
        Route::patch('/products/{product}/variants/{variant}', [AdminController::class, 'variant'])->name('variants.save');
        Route::get('/customers', [AdminController::class, 'customers'])->name('customers');
        Route::get('/settings', [AdminController::class, 'settings'])->name('settings');
        Route::post('/settings', [AdminController::class, 'saveSettings'])->name('settings.save');
    });
});
