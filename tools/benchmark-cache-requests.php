<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__) . '/build/cache-benchmark';
(new Filesystem())->remove($root);
mkdir($root . '/config', 0700, true);
$config = '<?php return static function (\\Symfony\\Component\\DependencyInjection\\Loader\\Configurator\\ContainerConfigurator $c): void { $c->parameters()->set("imported.value", "benchmark"); };';
file_put_contents($root . '/config/services.php', $config . "\n/*" . str_repeat('x', 1024 * 1024) . '*/');
$router = $root . '/router.php';
file_put_contents($router, '<?php $argv = [__FILE__, __DIR__]; require ' . var_export(dirname(__DIR__) . '/tests/Fixtures/cache-request.php', true) . ';');
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) {
    throw new RuntimeException($error, $errno);
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$server = new Process([PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=1', '-d', 'opcache.revalidate_freq=0', '-S', (string) $address, $router]);
$server->start();
try {
    for ($attempt = 0; $attempt < 100; ++$attempt) {
        if (str_contains($server->getErrorOutput(), 'Development Server')) {
            break;
        }
        usleep(10000);
    }
    $requests = [];
    for ($index = 0; $index < 41; ++$index) {
        $start = hrtime(true);
        $content = file_get_contents('http://' . $address . '/');
        if ($content === false) {
            throw new RuntimeException('Benchmark HTTP request failed.');
        }
        $result = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        $result['http_ms'] = (hrtime(true) - $start) / 1e6;
        $requests[] = $result;
    }
    $warm = array_slice($requests, 1);
    $latencies = array_column($warm, 'milliseconds');
    $http = array_column($warm, 'http_ms');
    sort($latencies);
    sort($http);
    echo json_encode([
        'php' => PHP_VERSION,
        'requests' => count($warm),
        'warm_hits' => count(array_filter($warm, static fn (array $request): bool => $request['hit'])),
        'hashed_bytes_per_request' => array_sum(array_column($warm, 'bytes')) / count($warm),
        'invalidations_per_request' => array_sum(array_column($warm, 'invalidations')) / count($warm),
        'cache_ms_p50' => $latencies[19],
        'cache_ms_p95' => $latencies[37],
        'http_ms_p50' => $http[19],
        'http_ms_p95' => $http[37],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} finally {
    $server->stop();
    (new Filesystem())->remove($root);
}
