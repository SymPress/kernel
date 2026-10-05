<?php

declare(strict_types=1);

namespace SymPress\Kernel\Discovery;

use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Kernel\SiteKernel;
use SymPress\Kernel\WpContext;

require __DIR__ . '/cache-hash-probe.php';
require __DIR__ . '/discovery-opcache-probe.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$project = $_SERVER['SYMPRESS_TEST_PROJECT'];
$kernel = new SiteKernel($project, 'test', false, null, WpContext::new()->force(WpContext::CORE));
$container = $kernel->createContainer();
$hit = $kernel->tryUseRuntimeContainer($container, new BundleRegistry());
echo json_encode([
    'hit' => $hit,
    'value' => $hit ? $container->getParameter('imported.value') : null,
    'packages' => (new KernelPackageManifestCache($project, 'test', ['sympress/']))->read(),
    'invalidations' => $GLOBALS['cache_request_invalidations'] ?? 0,
    'worker' => getmypid(),
    'validate_timestamps' => opcache_get_configuration()['directives']['opcache.validate_timestamps'],
], JSON_THROW_ON_ERROR);
