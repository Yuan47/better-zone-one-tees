<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CartSessionConcurrencyTest extends TestCase
{
    private string $directory;

    /** @var list<Process> */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'zone-one-cart-'.bin2hex(random_bytes(8));
        foreach (['bootstrap/cache', 'storage/logs', 'storage/framework/views', 'sessions', 'cache'] as $child) {
            mkdir($this->directory.'/'.$child, 0777, true);
        }
        $database = new PDO('sqlite:'.$this->directory.'/fixture.sqlite');
        $database->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, active INTEGER)');
        $database->exec("INSERT INTO products VALUES (1, 'Cart fixture', 'cart-fixture', 1), (2, 'Hidden fixture', 'hidden-fixture', 0)");
        $database->exec('CREATE TABLE variants (id INTEGER PRIMARY KEY, product_id INTEGER, stock INTEGER, reserved INTEGER, retail_price INTEGER, wholesale_price INTEGER, reseller_price INTEGER)');
        $database->exec('INSERT INTO variants VALUES (1, 1, 100, 0, 20000, 18000, 16000), (2, 1, 100, 0, 25000, 22000, 20000), (3, 2, 100, 0, 20000, 18000, 16000)');
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(0);
            }
        }
        $resolved = realpath($this->directory);
        $temporaryRoot = realpath(sys_get_temp_dir());
        if ($resolved !== false && $temporaryRoot !== false
            && str_starts_with($resolved, $temporaryRoot.DIRECTORY_SEPARATOR.'zone-one-cart-')) {
            (new Filesystem)->deleteDirectory($resolved);
        }
        parent::tearDown();
    }

    public static function overlappingRequests(): array
    {
        return [
            'two additions' => [[], ['add', 1, 2], ['add', 2, 3], [1 => 2, 2 => 3]],
            'same variant additions' => [[1 => 1], ['add', 1, 2], ['add', 1, 3], [1 => 6]],
            'two updates' => [[1 => 1, 2 => 1], ['update', 1, 4], ['update', 2, 5], [1 => 4, 2 => 5]],
            'removal and update' => [[1 => 1, 2 => 1], ['update', 1, 0], ['update', 2, 5], [2 => 5]],
            'cleanup and update' => [[1 => 1, 2 => 1, 3 => 1], ['cleanup', 0, 0], ['update', 2, 4], [1 => 1, 2 => 4]],
            'status and update' => [[1 => 1, 2 => 1], ['status', 0, 0], ['update', 1, 4], [1 => 4, 2 => 1]],
        ];
    }

    #[DataProvider('overlappingRequests')]
    public function test_overlapping_requests_preserve_cart_changes(array $initial, array $firstAction, array $secondAction, array $expected): void
    {
        $sessionId = bin2hex(random_bytes(20));
        file_put_contents($this->directory.'/sessions/'.$sessionId, json_encode(['_token' => str_repeat('t', 40), 'cart' => $initial], JSON_THROW_ON_ERROR));
        $first = $this->worker($sessionId, 'first', $firstAction);
        $this->awaitMarker('first.entered', $first);
        $second = $this->worker($sessionId, 'second', $secondAction);
        $this->awaitMarker('second.launched', $second);

        // Without locking, force both requests to load the same snapshot before either saves.
        if (! config('session.block', false)) {
            $this->awaitMarker('second.entered', $second);
            touch($this->directory.'/second.release');
            $this->awaitMarker('second.finished', $second);
        } else {
            usleep(200000);
            $this->assertFileDoesNotExist($this->directory.'/second.entered', 'The second request must wait before reading its session.');
            touch($this->directory.'/second.release');
        }
        touch($this->directory.'/first.release');
        foreach (['first' => $first, 'second' => $second] as $name => $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
            $this->assertFileExists($this->directory.'/'.$name.'.finished', $worker->getOutput());
        }
        $stored = json_decode(file_get_contents($this->directory.'/sessions/'.$sessionId), true, flags: JSON_THROW_ON_ERROR);
        $this->assertEquals($expected, $stored['cart'], $first->getOutput().' / '.$second->getOutput());
    }

    public function test_a_slow_request_does_not_block_a_different_browser_session(): void
    {
        $first = $this->worker(bin2hex(random_bytes(20)), 'first', ['add', 1, 2]);
        $this->awaitMarker('first.entered', $first);
        $second = $this->worker(bin2hex(random_bytes(20)), 'second', ['add', 2, 3]);
        $this->awaitMarker('second.entered', $second);
        touch($this->directory.'/second.release');
        $this->awaitMarker('second.finished', $second);
        $this->assertFileDoesNotExist($this->directory.'/first.finished');
        touch($this->directory.'/first.release');
        foreach ([$first, $second] as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
        }
    }

    public static function responseTypes(): array
    {
        return [['text/html'], ['application/json']];
    }

    #[DataProvider('responseTypes')]
    public function test_lock_wait_timeout_returns_a_safe_retry_response_without_mutating_the_cart(string $accept): void
    {
        $sessionId = bin2hex(random_bytes(20));
        $first = $this->worker($sessionId, 'first', ['add', 1, 2]);
        $this->awaitMarker('first.entered', $first);
        $second = $this->worker($sessionId, 'second', ['add', 2, 3], ['1', $accept]);
        $this->awaitMarker('second.finished', $second);
        $second->wait();
        $result = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(503, $result['status']);
        $this->assertNull($result['cart'], 'A timed-out request must not load or save a stale session.');
        $this->assertStringContainsString('previous request is still finishing', $result['body']);
        $this->assertStringNotContainsString('LockTimeoutException', $result['body']);
        $this->assertStringContainsString('no-store', $result['cache_control']);
        $this->assertFileDoesNotExist($this->directory.'/second.entered');
        touch($this->directory.'/first.release');
        $this->awaitMarker('first.finished', $first);
        $first->wait();
        $stored = json_decode(file_get_contents($this->directory.'/sessions/'.$sessionId), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([1 => 2], $stored['cart']);
    }

    private function worker(string $sessionId, string $name, array $action, array $options = []): Process
    {
        $worker = new Process([PHP_BINARY, base_path('tests/Support/CartSessionWorker.php'), $this->directory, $sessionId, $name, ...array_map(strval(...), $action), ...$options], base_path());
        $worker->setTimeout(15);
        $worker->start();
        $this->workers[] = $worker;

        return $worker;
    }

    private function awaitMarker(string $marker, Process $worker): void
    {
        $deadline = microtime(true) + 8;
        while (! is_file($this->directory.'/'.$marker)) {
            if (! $worker->isRunning() || microtime(true) >= $deadline) {
                $this->fail('The isolated cart worker did not reach '.$marker.': '.$worker->getErrorOutput().$worker->getOutput());
            }
            usleep(10000);
            clearstatcache();
        }
    }
}
