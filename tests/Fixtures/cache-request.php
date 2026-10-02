<?php

declare(strict_types=1);

namespace SymPress\Kernel\Kernel;

use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\WpContext;

require __DIR__ . '/cache-hash-probe.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$kernel = new SiteKernel($argv[1], 'test', false, null, WpContext::new()->force(WpContext::CORE));
$container = $kernel->createContainer();
$bundles = new BundleRegistry();
$start = hrtime(true);
$hit = $kernel->tryUseRuntimeContainer($container, $bundles);
if (!$hit) {
    $files = $kernel->configureContainer($container->builder(), $container, $bundles);
    $kernel->createRuntimeContainer($container, $bundles, $files);
}
echo json_encode(['hit' => $hit, 'hashes' => $GLOBALS['cache_request_hashes'] ?? [], 'bytes' => $GLOBALS['cache_request_bytes'] ?? 0, 'milliseconds' => (hrtime(true) - $start) / 1e6, 'value' => $container->getParameter('imported.value')], JSON_THROW_ON_ERROR);
