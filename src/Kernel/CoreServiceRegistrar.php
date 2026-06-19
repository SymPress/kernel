<?php

declare(strict_types=1);

namespace SymPress\Kernel\Kernel;

use SymPress\Kernel\Attribute\AsHook;
use SymPress\Kernel\Attribute\Route;
use SymPress\Kernel\Console\ConsoleApplicationFactory;
use SymPress\Kernel\Console\WpCliConsoleBridge;
use SymPress\Kernel\Container;
use SymPress\Kernel\Hook\HookCompilerPass;
use SymPress\Kernel\Hook\HookLoader;
use SymPress\Kernel\Routing\RouteCompilerPass;
use SymPress\Kernel\Routing\RouteLoader;
use SymPress\Kernel\Translation\TranslationLoader;
use Symfony\Component\Config\Resource\SelfCheckingResourceChecker;
use Symfony\Component\Config\ResourceCheckerConfigCacheFactory;
use Symfony\Component\Config\ResourceCheckerInterface;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\AddBehaviorDescribingTagsPass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\Compiler\ResettableServicePass;
use Symfony\Component\DependencyInjection\Config\ContainerParametersResourceChecker;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface as SymfonyDiContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\EnvVarLoaderInterface;
use Symfony\Component\DependencyInjection\EnvVarProcessor;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Kernel\FileLocator;
use Symfony\Component\DependencyInjection\Kernel\KernelInterface as DependencyInjectionKernelInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBag;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ReverseContainer;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\DependencyInjection\ServicesResetter;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

final class CoreServiceRegistrar
{
    /** @var array<int, true> */
    private array $preparedBuilders = [];

    public function prepare(ContainerBuilder $builder, \Closure $build): void
    {
        $builderId = spl_object_id($builder);

        if (($this->preparedBuilders[$builderId] ?? false) === true) {
            return;
        }

        $this->preparedBuilders[$builderId] = true;
        $this->registerCoreContainerServices($builder);
        $this->registerTranslationLoader($builder);
        $this->registerHookLoader($builder);
        $this->registerRouteLoader($builder);
        $this->registerConsoleApplication($builder);
        $this->registerConsoleAttributes($builder);
        $builder->registerAttributeForAutoconfiguration(
            AsHook::class,
            static function (ChildDefinition $definition, AsHook $attribute, \Reflector $reflector): void {
                if (!$reflector instanceof \ReflectionClass && !$reflector instanceof \ReflectionMethod) {
                    return;
                }

                $tag = $attribute->toTag();

                if ($reflector instanceof \ReflectionMethod && $attribute->method === '__invoke') {
                    $tag['method'] = $reflector->getName();
                }

                $definition->addTag(HookLoader::TAG, $tag);
            },
        );
        $this->registerRouteAttributes($builder);
        $builder->addCompilerPass(new HookCompilerPass());
        $builder->addCompilerPass(new RouteCompilerPass());
        $build($builder);
    }

    private function registerCoreContainerServices(ContainerBuilder $builder): void
    {
        if (!$builder->has('kernel')) {
            $builder->setAlias('kernel', Container::KERNEL_ID)->setPublic(true);
        }

        $builder->setAlias(HttpKernelInterface::class, Container::KERNEL_ID)
            ->setPublic(true);
        $builder->setAlias(DependencyInjectionKernelInterface::class, Container::KERNEL_ID)
            ->setPublic(true);

        $this->registerFilesystemService($builder);
        $this->registerEventDispatcherServices($builder);
        $this->registerClockService($builder);
        $this->registerExpressionLanguageService($builder);

        if (!$builder->hasDefinition('parameter_bag')) {
            $builder->setDefinition(
                'parameter_bag',
                (new Definition(ContainerBag::class))
                    ->setArguments([new Reference('service_container')]),
            );
        }

        $builder->setAlias(ContainerBagInterface::class, 'parameter_bag')->setPublic(false);
        $builder->setAlias(ParameterBagInterface::class, 'parameter_bag')->setPublic(false);

        if (!$builder->hasDefinition('file_locator')) {
            $builder->setDefinition(
                'file_locator',
                (new Definition(FileLocator::class))
                    ->setArguments([new Reference(Container::KERNEL_ID)]),
            );
        }

        $builder->setAlias(FileLocator::class, 'file_locator')->setPublic(false);

        if (!$builder->hasDefinition('reverse_container')) {
            $builder->setDefinition(
                'reverse_container',
                (new Definition(ReverseContainer::class))
                    ->setArguments([
                        new Reference('service_container'),
                        new ServiceLocatorArgument([]),
                    ]),
            );
        }

        $builder->setAlias(ReverseContainer::class, 'reverse_container')->setPublic(false);

        if (!$builder->hasDefinition('config_cache_factory')) {
            $builder->setDefinition(
                'config_cache_factory',
                (new Definition(ResourceCheckerConfigCacheFactory::class))
                    ->setArguments([new TaggedIteratorArgument('config_cache.resource_checker')]),
            );
        }

        if (!$builder->hasDefinition('dependency_injection.config.container_parameters_resource_checker')) {
            $builder->setDefinition(
                'dependency_injection.config.container_parameters_resource_checker',
                (new Definition(ContainerParametersResourceChecker::class))
                    ->setArguments([new Reference('service_container')])
                    ->addTag('config_cache.resource_checker', ['priority' => -980]),
            );
        }

        if (!$builder->hasDefinition('config.resource.self_checking_resource_checker')) {
            $builder->setDefinition(
                'config.resource.self_checking_resource_checker',
                (new Definition(SelfCheckingResourceChecker::class))
                    ->addTag('config_cache.resource_checker', ['priority' => -990]),
            );
        }

        if (!$builder->hasDefinition('services_resetter')) {
            $builder->setDefinition(
                'services_resetter',
                (new Definition(ServicesResetter::class))
                    ->setPublic(true)
                    ->setArguments([new IteratorArgument([]), []]),
            );
        }

        $builder->setAlias(ServicesResetterInterface::class, 'services_resetter')->setPublic(true);

        if (!$builder->hasDefinition('container.env_var_processor')) {
            $builder->setDefinition(
                'container.env_var_processor',
                (new Definition(EnvVarProcessor::class))
                    ->setArguments([
                        new Reference('service_container'),
                        new TaggedIteratorArgument('container.env_var_loader'),
                    ])
                    ->addTag('container.env_var_processor')
                    ->addTag('kernel.reset', ['method' => 'reset']),
            );
        }

        $builder->registerForAutoconfiguration(EnvVarLoaderInterface::class)
            ->addTag('container.env_var_loader');
        $builder->registerForAutoconfiguration(EnvVarProcessorInterface::class)
            ->addTag('container.env_var_processor');
        $builder->registerForAutoconfiguration(ResourceCheckerInterface::class)
            ->addTag('config_cache.resource_checker');
        $builder->registerForAutoconfiguration(ServiceLocator::class)
            ->addTag('container.service_locator');
        $builder->registerForAutoconfiguration(ResetInterface::class)
            ->addTag('kernel.reset', ['method' => 'reset']);
        $builder->registerForAutoconfiguration(ServiceSubscriberInterface::class)
            ->addTag('container.service_subscriber');
        $builder->registerForAutoconfiguration(CompilerPassInterface::class)
            ->addTag('container.excluded', ['source' => 'because it is a compiler pass']);
        $builder->registerForAutoconfiguration(\UnitEnum::class)
            ->addTag('container.excluded', ['source' => 'because it is an enum']);
        $builder->registerAttributeForAutoconfiguration(
            \Attribute::class,
            static function (ChildDefinition $definition): void {
                $definition->addTag('container.excluded', ['source' => 'because it is a PHP attribute']);
            },
        );
        $this->registerOptionalAutoconfiguration($builder);

        $builder->addCompilerPass(
            new AddBehaviorDescribingTagsPass(
                [
                    'container.do_not_inline',
                    'container.excluded',
                    'container.hot_path',
                    'container.service_locator',
                    'container.service_subscriber',
                    'event_dispatcher.dispatcher',
                    'kernel.event_listener',
                    'kernel.event_subscriber',
                    'kernel.reset',
                ],
            ),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            200,
        );
        $builder->addCompilerPass(new ResettableServicePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -32);
    }

    private function registerFilesystemService(ContainerBuilder $builder): void
    {
        if (!$builder->hasDefinition('filesystem')) {
            $builder->setDefinition('filesystem', new Definition(Filesystem::class));
        }

        $this->setAliasIfMissing($builder, Filesystem::class, 'filesystem');
    }

    private function registerEventDispatcherServices(ContainerBuilder $builder): void
    {
        $eventDispatcherClass = 'Symfony\Component\EventDispatcher\EventDispatcher';

        if (class_exists($eventDispatcherClass) && !$builder->hasDefinition('event_dispatcher')) {
            $builder->setDefinition(
                'event_dispatcher',
                (new Definition($eventDispatcherClass))
                    ->setPublic(true)
                    ->addTag('container.hot_path')
                    ->addTag('event_dispatcher.dispatcher', ['name' => 'event_dispatcher']),
            );
        }

        $this->registerEventDispatcherAliases($builder);

        $registerListenersPass = 'Symfony\Component\EventDispatcher\DependencyInjection\RegisterListenersPass';

        if (class_exists($registerListenersPass)) {
            $builder->addCompilerPass(new $registerListenersPass(), PassConfig::TYPE_BEFORE_REMOVING);
        }

        foreach (
            [
                'Symfony\Component\EventDispatcher\EventDispatcherInterface' => 'event_dispatcher.dispatcher',
                'Symfony\Component\EventDispatcher\EventSubscriberInterface' => 'kernel.event_subscriber',
            ] as $interface => $tag
        ) {
            if (!interface_exists($interface)) {
                continue;
            }

            $builder->registerForAutoconfiguration($interface)->addTag($tag);
        }

        $asEventListenerClass = 'Symfony\Component\EventDispatcher\Attribute\AsEventListener';

        if (!class_exists($asEventListenerClass)) {
            return;
        }

        $builder->registerAttributeForAutoconfiguration(
            $asEventListenerClass,
            static function (ChildDefinition $definition, object $attribute, \Reflector $reflector): void {
                if (!$reflector instanceof \ReflectionClass && !$reflector instanceof \ReflectionMethod) {
                    return;
                }

                $tagAttributes = array_filter(
                    get_object_vars($attribute),
                    static fn (mixed $value): bool => $value !== null,
                );

                if ($reflector instanceof \ReflectionMethod) {
                    if (isset($tagAttributes['method'])) {
                        throw new \LogicException(
                            sprintf(
                                'AsEventListener attribute cannot declare a method on "%s::%s()".',
                                $reflector->class,
                                $reflector->name,
                            ),
                        );
                    }

                    $tagAttributes['method'] = $reflector->getName();
                }

                $definition->addTag('kernel.event_listener', $tagAttributes);
            },
        );
    }

    private function registerEventDispatcherAliases(ContainerBuilder $builder): void
    {
        if (!$builder->hasDefinition('event_dispatcher') && !$builder->hasAlias('event_dispatcher')) {
            return;
        }

        foreach (
            [
                'Symfony\Component\EventDispatcher\EventDispatcherInterface',
                'Symfony\Contracts\EventDispatcher\EventDispatcherInterface',
                'Psr\EventDispatcher\EventDispatcherInterface',
            ] as $eventDispatcherInterface
        ) {
            if (!interface_exists($eventDispatcherInterface)) {
                continue;
            }

            $this->setAliasIfMissing($builder, $eventDispatcherInterface, 'event_dispatcher', true);
        }
    }

    private function registerClockService(ContainerBuilder $builder): void
    {
        $clockClass = 'Symfony\Component\Clock\Clock';

        if (!class_exists($clockClass)) {
            return;
        }

        if (!$builder->hasDefinition('clock')) {
            $builder->setDefinition('clock', new Definition($clockClass));
        }

        foreach (['Symfony\Component\Clock\ClockInterface', 'Psr\Clock\ClockInterface'] as $clockInterface) {
            if (!interface_exists($clockInterface)) {
                continue;
            }

            $this->setAliasIfMissing($builder, $clockInterface, 'clock');
        }
    }

    private function registerExpressionLanguageService(ContainerBuilder $builder): void
    {
        $expressionLanguageClass = 'Symfony\Component\DependencyInjection\ExpressionLanguage';

        if (!class_exists($expressionLanguageClass) || $builder->hasDefinition('container.expression_language')) {
            return;
        }

        $builder->setDefinition('container.expression_language', new Definition($expressionLanguageClass));
    }

    private function registerOptionalAutoconfiguration(ContainerBuilder $builder): void
    {
        $this->registerLoggerAwareAutoconfiguration($builder);
        $this->registerTestCaseExclusion($builder);
        $this->registerLoaderInterfaceExclusion($builder);
    }

    private function registerLoggerAwareAutoconfiguration(ContainerBuilder $builder): void
    {
        $loggerAwareInterface = 'Psr\Log\LoggerAwareInterface';

        if (!interface_exists($loggerAwareInterface)) {
            return;
        }

        $builder->registerForAutoconfiguration($loggerAwareInterface)
            ->addMethodCall(
                'setLogger',
                [new Reference('logger', SymfonyDiContainerInterface::IGNORE_ON_INVALID_REFERENCE)],
            );
    }

    private function registerTestCaseExclusion(ContainerBuilder $builder): void
    {
        $testCaseClass = 'PHPUnit\Framework\TestCase';

        if (!class_exists($testCaseClass)) {
            return;
        }

        $builder->registerForAutoconfiguration($testCaseClass)
            ->addTag('container.excluded', ['source' => 'because it is a test case']);
    }

    private function registerLoaderInterfaceExclusion(ContainerBuilder $builder): void
    {
        if ($builder->hasDefinition(LoaderInterface::class)) {
            return;
        }

        $builder->setDefinition(
            LoaderInterface::class,
            (new Definition())
                ->setAbstract(true)
                ->addTag('container.excluded', ['source' => 'because it is a loader interface']),
        );
    }

    private function setAliasIfMissing(
        ContainerBuilder $builder,
        string $alias,
        string $target,
        bool $public = false,
    ): void {
        if ($builder->hasAlias($alias) || $builder->hasDefinition($alias)) {
            return;
        }

        $builder->setAlias($alias, $target)->setPublic($public);
    }

    private function registerTranslationLoader(ContainerBuilder $builder): void
    {
        if ($builder->hasDefinition(TranslationLoader::class)) {
            return;
        }

        $builder->setDefinition(
            TranslationLoader::class,
            (new Definition(TranslationLoader::class))
                ->setPublic(true)
                ->setArguments(['%kernel.translation_paths%']),
        );
    }

    private function registerConsoleApplication(ContainerBuilder $builder): void
    {
        if (!$builder->hasDefinition(ConsoleApplicationFactory::class)) {
            $builder->setDefinition(
                ConsoleApplicationFactory::class,
                (new Definition(ConsoleApplicationFactory::class))
                    ->setPublic(true)
                    ->setArguments([
                        new Reference(Container::KERNEL_ID),
                        new Reference('console.command_loader'),
                    ]),
            );
        }

        if (!$builder->hasDefinition(Application::class)) {
            $builder->setDefinition(
                Application::class,
                (new Definition(Application::class))
                    ->setPublic(true)
                    ->setFactory([
                        new Reference(ConsoleApplicationFactory::class),
                        'create',
                    ]),
            );
        }

        if ($builder->hasDefinition(WpCliConsoleBridge::class)) {
            return;
        }

        $builder->setDefinition(
            WpCliConsoleBridge::class,
            (new Definition(WpCliConsoleBridge::class))
                ->setArguments([new Reference(Application::class)])
                ->addTag(
                    HookLoader::TAG,
                    [
                        'hook'     => 'muplugins_loaded',
                        'method'   => 'register',
                        'priority' => 1,
                    ],
                ),
        );
    }

    private function registerConsoleAttributes(ContainerBuilder $builder): void
    {
        $builder->registerAttributeForAutoconfiguration(
            AsCommand::class,
            static function (ChildDefinition $definition): void {
                $definition->addTag('console.command');
            },
        );
    }

    private function registerHookLoader(ContainerBuilder $builder): void
    {
        if ($builder->hasDefinition(HookLoader::class)) {
            return;
        }

        $builder->setDefinition(
            HookLoader::class,
            (new Definition(HookLoader::class))
                ->setPublic(true)
                ->setArguments([null, []]),
        );
    }

    private function registerRouteLoader(ContainerBuilder $builder): void
    {
        if ($builder->hasDefinition(RouteLoader::class)) {
            return;
        }

        $builder->setDefinition(
            RouteLoader::class,
            (new Definition(RouteLoader::class))
                ->setPublic(true)
                ->setArguments([null, [], []])
                ->addTag(
                    HookLoader::TAG,
                    [
                        'hook'     => 'template_redirect',
                        'method'   => 'dispatchFrontendRequest',
                        'priority' => 0,
                    ],
                )
                ->addTag(
                    HookLoader::TAG,
                    [
                        'hook'   => 'rest_api_init',
                        'method' => 'registerRestRoutes',
                    ],
                ),
        );
    }

    private function registerRouteAttributes(ContainerBuilder $builder): void
    {
        foreach (
            [
                Route::class,
                'Symfony\Component\Routing\Attribute\Route',
            ] as $attributeClass
        ) {
            if (!class_exists($attributeClass)) {
                continue;
            }

            $builder->registerAttributeForAutoconfiguration(
                $attributeClass,
                static function (ChildDefinition $definition): void {
                    $definition->addTag(RouteLoader::TAG);
                },
            );
        }
    }
}
