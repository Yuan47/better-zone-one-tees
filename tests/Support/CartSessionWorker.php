<?php

use App\Http\Controllers\CustomerSessionController;
use App\Http\Controllers\StoreController;
use App\Models\User;
use App\Models\Variant;
use App\Services\Pricing;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $directory, $sessionId, $name, $operation, $variantId, $quantity] = $argv;
$directory = realpath($directory);
$temporaryRoot = realpath(sys_get_temp_dir());
if ($directory === false || $temporaryRoot === false
    || ! str_starts_with($directory, $temporaryRoot.DIRECTORY_SEPARATOR.'zone-one-cart-')) {
    throw new RuntimeException('Only an isolated cart test fixture may be used.');
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath($directory);
$app->useBootstrapPath($directory.'/bootstrap');
$app->useStoragePath($directory.'/storage');
$app->make(Kernel::class)->bootstrap();
config([
    'app.key' => 'base64:'.base64_encode(str_repeat('c', 32)), 'app.url' => 'http://localhost',
    'database.default' => 'sqlite', 'database.connections.sqlite.database' => $directory.'/fixture.sqlite',
    'database.connections.sqlite.url' => null,
    'session.driver' => 'file', 'session.files' => $directory.'/sessions', 'session.cookie' => 'cart_test_session',
    'session.encrypt' => false, 'session.serialization' => 'json',
    'cache.default' => 'file', 'cache.stores.file.path' => $directory.'/cache', 'cache.stores.file.lock_path' => $directory.'/cache',
]);
if (isset($argv[7])) {
    config(['session.block_wait_seconds' => (int) $argv[7]]);
}

$method = in_array($operation, ['add', 'status'], true) ? 'POST' : ($operation === 'update' ? 'PATCH' : 'GET');
$path = $operation === 'status' ? '/session/status' : ($operation === 'update' ? '/cart/'.$variantId : '/cart');
$request = Request::create($path, $method, ['variant_id' => (int) $variantId, 'quantity' => (int) $quantity],
    ['cart_test_session' => $sessionId], [], ['HTTP_ACCEPT' => $argv[8] ?? 'text/html', 'HTTP_REFERER' => 'http://localhost/cart']);
$route = $app['router']->getRoutes()->match($request);
$request->setRouteResolver(fn () => $route);
$app->instance('request', $request);
$request->setUserResolver(fn () => (new User)->forceFill(['id' => 1, 'role' => 'customer']));
file_put_contents($directory.'/'.$name.'.launched', 'ready');

$action = function (Request $request) use ($app, $directory, $name, $operation, $variantId) {
    file_put_contents($directory.'/'.$name.'.entered', 'session loaded');
    $deadline = microtime(true) + 10;
    while (! is_file($directory.'/'.$name.'.release')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('The cart test barrier timed out.');
        }
        usleep(10000);
    }
    $controller = $app->make(StoreController::class);

    return match ($operation) {
        'add' => $controller->add($request),
        'update' => $controller->update($request, Variant::findOrFail($variantId)),
        'cleanup' => (function () use ($controller, $request) {
            $controller->cart($request, new Pricing);

            return response('Cart reviewed.');
        })(),
        'status' => $app->make(CustomerSessionController::class)->show($request),
        default => throw new RuntimeException('Unknown cart test operation.'),
    };
};
try {
    $response = $app->make(StartSession::class)->handle($request, $action);
} catch (LockTimeoutException $exception) {
    $response = $app->make(ExceptionHandler::class)->render($request, $exception);
}
file_put_contents($directory.'/'.$name.'.finished', (string) $response->getStatusCode());
echo json_encode(['status' => $response->getStatusCode(), 'cart' => $request->hasSession() ? $request->session()->get('cart', []) : null,
    'body' => $response->getContent(), 'cache_control' => $response->headers->get('Cache-Control')], JSON_THROW_ON_ERROR);
