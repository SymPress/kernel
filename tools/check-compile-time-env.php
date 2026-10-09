<?php

declare(strict_types=1);

use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

require dirname(__DIR__) . '/vendor/autoload.php';

if (!method_exists(NodeDefinition::class, 'resolvesAtCompileTime')) {
    fwrite(STDERR, "Native compile-time configuration diagnostics require Symfony Config/DI 8.2, which is not yet stable.\n");
    exit(2);
}

$container = new ContainerBuilder();
$container->registerExtension(new class extends Extension {
    public function getAlias(): string
    {
        return 'fixture';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new class implements ConfigurationInterface {
            public function getConfigTreeBuilder(): TreeBuilder
            {
                $tree = new TreeBuilder('fixture');
                $node = $tree->getRootNode()->children()->booleanNode('enabled');
                $node->resolvesAtCompileTime();
                return $tree;
            }
        };
        $config = $this->processConfiguration($configuration, $configs);
        $container->setParameter('fixture.enabled', $config['enabled']);
    }
});
$container->loadFromExtension('fixture', ['enabled' => '%env(bool:SYMPRESS_TEST_FEATURE)%']);
$previous = $_ENV['SYMPRESS_TEST_FEATURE'] ?? null;
$_ENV['SYMPRESS_TEST_FEATURE'] = '1';
try {
    (new MergeExtensionConfigurationPass())->process($container);
    if ($container->getParameter('fixture.enabled') !== true) {
        throw new RuntimeException('Native compile-time resolution was not applied.');
    }
    foreach ($container->getCompiler()->getLog() as $line) {
        if (preg_match('/Inlined env var "%env\(([A-Za-z0-9_:]+)\)%" into option "([A-Za-z0-9_.-]+)"\./', $line, $matches) === 1) {
            if ($matches[1] === 'bool:SYMPRESS_TEST_FEATURE' && $matches[2] === 'fixture.enabled') {
                echo $matches[1] . "\toption=" . $matches[2] . "\n";
                exit(0);
            }
        }
    }
    throw new RuntimeException('The native compiler log did not identify the inlined variable and configuration path.');
} finally {
    if ($previous === null) {
        unset($_ENV['SYMPRESS_TEST_FEATURE']);
    } else {
        $_ENV['SYMPRESS_TEST_FEATURE'] = $previous;
    }
}
