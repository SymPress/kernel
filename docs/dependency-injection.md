# Dependency Injection Kernel

Diese Dokumentation beschreibt den `sympress/kernel` als WordPress-Kernel auf
Basis von `symfony/dependency-injection`. Sie orientiert sich an der Symfony
Component-Dokumentation und ordnet die Learn-More-Themen auf die konkrete
Kernel-Implementierung dieses Repositories ein.

## Installation

Der Kernel ist ein Composer-Paket und benoetigt die Symfony-Komponenten, die er
zur Container-Erzeugung und Konfiguration verwendet:

```json
{
  "require": {
    "symfony/config": "^8.0",
    "symfony/console": "^8.0",
    "symfony/clock": "^8.0",
    "symfony/dependency-injection": "^8.1",
    "symfony/event-dispatcher": "^8.0",
    "symfony/expression-language": "^8.0",
    "symfony/filesystem": "^8.0",
    "symfony/service-contracts": "^3.6",
    "symfony/yaml": "^8.0"
  }
}
```

Im Projekt wird `sympress/kernel` ueber Composer eingebunden und ueber den
WordPress-MU-Bootstrap gestartet. Der Kernel erzeugt genau einen globalen
Site-Container fuer aktive MU-Plugins, Plugins, Themes und Site-Konfiguration.

```php
<?php

declare(strict_types=1);

use SymPress\Kernel\App;
use SymPress\Kernel\Kernel\SiteKernel;

App::bootKernel(new SiteKernel(dirname(__DIR__, 2)));
```

## Grundidee

Symfony beschreibt den Dependency-Injection-Container als zentrale Stelle, an
der die Konstruktion von Objekten standardisiert wird. Der Kernel uebernimmt
diese Idee fuer WordPress:

- Services werden im Container definiert, nicht in Plugin-Bootstrap-Dateien.
- Plugins und Themes liefern Bundles mit `Resources/config/` oder `config/`.
- Die Runtime nutzt einen kompilierten Symfony-Container statt verstreuter
  globaler Singletons.
- WordPress-Hooks werden als Service-Tags oder Attribute beschrieben und beim
  Booten registriert.

Der Kernel ist dabei keine Kopie des Symfony FrameworkBundle. Er nutzt das
originale DependencyInjection-API, insbesondere `ContainerBuilder`,
`Definition`, `Reference`, `DelegatingLoader`, `PhpFileLoader`,
`YamlFileLoader`, `IniFileLoader`, `GlobFileLoader`, `DirectoryLoader`,
`ClosureLoader`, `BundleExtension`, `PhpDumper`, Compiler Passes,
Attribute-Autoconfiguration, `ServiceLocatorTagPass`, `ResettableServicePass`
und `MergeExtensionConfigurationPass`, und setzt darueber eine
WordPress-spezifische Bundle- und Hook-Schicht.

## Boot-Ablauf

Der zentrale Einstieg ist `SymPress\Kernel\App::boot()`.

1. `App` erstellt den Kernel und den initialen Wrapper-Container.
2. Der Kernel entdeckt aktive Bundles ueber Composer-Metadaten, `config/bundles.php`
   und den Legacy-Filter `symfony_register_bundles`.
3. Wenn ein passender Runtime-Container im Cache liegt, wird dieser direkt
   verwendet.
4. Andernfalls wird der `ContainerBuilder` vorbereitet, Bundle-Extensions
   werden registriert, Konfiguration wird geladen, der Builder wird kompiliert
   und per `PhpDumper` als PHP-Klasse in `var/cache/<env>/kernel` abgelegt.
5. Der Runtime-Container wird mit synthetischen Runtime-Instanzen befuellt:
   Kernel, App, SiteConfig, WordPress-Kontext und Wrapper-Container.
6. `HookLoader` registriert die kompilierten `kernel.hook`-Eintraege bei
   WordPress.
7. Bundles und Kernel gelten danach als gebootet.

Der Kernel emittiert waehrenddessen WordPress-Actions:

- `kernel.booting`
- `kernel.before_container_build`
- `kernel.container_configured`
- `kernel.container_ready`
- `kernel.booted`
- `kernel.error`

Zusaetzlich existieren Legacy-Actions:

- `symfony_before_container_build`
- `symfony_container_ready`
- `symfony_container_loaded`

## Bundle Discovery

Ein Bundle wird ueber `composer.json > extra.kernel` beschrieben:

```json
{
  "type": "wordpress-plugin",
  "extra": {
    "kernel": {
      "bundle": "Acme\\Demo\\DemoBundle",
      "entry": "demo/demo.php"
    }
  }
}
```

Fuer abhaengige Bundles kann `requires` gesetzt werden. Ein Paket wird nur als
Bundle geladen, wenn seine Anforderungen installiert und aktiv sind.

```json
{
  "extra": {
    "kernel": {
      "bundle": "Acme\\MailerPro\\MailerProBundle",
      "entry": "mailer-pro/mailer-pro.php",
      "requires": ["acme/mailer"]
    }
  }
}
```

Die Discovery kann im Root-Projekt auf Paket-Prefixe eingeschraenkt werden:

```json
{
  "extra": {
    "kernel": {
      "package_prefixes": ["sympress/", "brianvarskonst/"]
    }
  }
}
```

Alternativ sind manuelle Bundles moeglich:

```php
<?php

return [
    Acme\Demo\DemoBundle::class => ['all' => true],
    Acme\Demo\DebugBundle::class => ['development' => true],
];
```

Discovery-Reihenfolge:

1. Composer-Bundles aus aktiven MU-Plugins, Plugins, Themes und Libraries.
2. Manuelle Bundles aus `config/bundles.php`.
3. Legacy-Bundles aus `symfony_register_bundles`.
4. Sortierung nach Typ: MU-Plugin, Plugin, Theme, Library.

## Bundle-Klasse

Eine Bundle-Klasse ist absichtlich klein:

```php
<?php

declare(strict_types=1);

namespace Acme\Demo;

use SymPress\Kernel\Bundle\AbstractBundle;

final class DemoBundle extends AbstractBundle
{
}
```

`AbstractBundle` sucht automatisch nach:

- `Resources/config/`
- `config/`
- `Resources/translations/`
- `DependencyInjection\<BundleName>Extension`

`build(ContainerBuilder $container)` sollte nur ueberschrieben werden, wenn ein
Bundle Compiler Passes oder sehr spezifische Container-Anpassungen registrieren
muss. Wird `build()` ueberschrieben, sollte `parent::build($container)` erhalten
bleiben, damit automatische Extension-Registrierung weiterhin funktioniert.

Alternativ kann ein Bundle die Symfony-8.1-Methoden direkt implementieren. Wenn
keine klassische `DependencyInjection\<BundleName>Extension` existiert, erstellt
der Kernel eine `BundleExtension` und ruft `configure()`, `prependExtension()`
und `loadExtension()` auf dem Bundle auf.

```php
<?php

declare(strict_types=1);

namespace Acme\Demo;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use SymPress\Kernel\Bundle\AbstractBundle;

final class DemoBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('api_key')->defaultValue('')->end()
            ->end();
    }

    public function loadExtension(
        array $config,
        ContainerConfigurator $configurator,
        ContainerBuilder $container,
    ): void {
        $configurator->import($this->getPath() . '/Resources/config/services.yaml');
        $container->setParameter('demo.api_key', $config['api_key']);
    }
}
```

Bundles erhalten ausserdem den Symfony-Lifecycle: `setContainer()`, `boot()`
und `shutdown()` werden vom Kernel um den Runtime-Container herum ausgefuehrt.

## Container-Konfiguration

Der Kernel laedt Konfiguration in dieser Reihenfolge:

1. Kernel-eigene `packages/kernel/config`
2. Bundle-Konfigurationen aus `Resources/config/` und `config/`
3. Site-Konfiguration aus `<project>/config`

Dadurch gewinnt die Site-Konfiguration gegen Bundle-Defaults.

Pro Konfigurationsverzeichnis werden diese Muster geladen:

```text
packages/*.{php,yaml,yml,ini}
packages/<env>/*.{php,yaml,yml,ini}
services.{php,yaml,yml,ini}
services_<env>.{php,yaml,yml,ini}
wordpress.{php,yaml,yml,ini}
wordpress_<env>.{php,yaml,yml,ini}
```

Konfiguration wird ueber Symfonys `DelegatingLoader` gelesen:

- `PhpFileLoader`
- `YamlFileLoader`
- `IniFileLoader`
- `GlobFileLoader`
- `DirectoryLoader`
- `ClosureLoader`
- `SymPress\Kernel\Kernel\FileLocator`

Der Kernel-`FileLocator` delegiert normale Pfade an Symfony und loest
Bundle-Ressourcen wie `@DemoBundle/Resources/config/services.yaml` ueber
`KernelInterface::locateResource()` auf.

Eine typische Bundle-Konfiguration in `Resources/config/services.yaml` sieht so aus:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: false

    Acme\Demo\:
        resource: '../../src/'
        exclude:
            - '../../src/DemoBundle.php'
```

`config/services.yaml` wird weiterhin als Fallback unterstuetzt. Dort bleibt
der relative Pfad entsprechend `../src/`.

Diese Resource-Definition ist wichtig, weil Symfony-Attribute nur dann
zuverlaessig greifen, wenn die betroffenen Klassen gescannt werden.

## Parameter

Der Kernel setzt zentrale Parameter:

```text
kernel.project_dir
kernel.environment
kernel.debug
kernel.cache_dir
kernel.build_dir
kernel.share_dir
kernel.logs_dir
kernel.package_prefixes
kernel.translation_paths
kernel.bundles
kernel.bundles_metadata
kernel.container_class
.kernel.config_dir
```

Ausserdem werden ausgewahlte Environment-Variablen als Parameter verfuegbar:

- `APP_*`
- `DB_*`
- `WORDPRESS_*`
- `WP_*`

Ausgeschlossen bleiben typische Request- und PHP-Server-Werte wie `HTTP_*`,
`REQUEST_*`, `SCRIPT_*`, `PHP_*` und `DOCUMENT_*`.

Beispiel:

```yaml
services:
    Acme\Demo\Service\ApiClient:
        arguments:
            $baseUrl: '%env.app_api_base_url%'
```

Das Prozentzeichen in String-Werten wird beim Import escaped, damit Symfony
Parameter-Platzhalter nicht versehentlich interpretiert werden.

## Services und Autowiring

Der Kernel folgt dem Symfony-Standard: Konstruktor-Injection ist der Normalfall,
explizite Definitionen sind fuer Sonderfaelle da.

```php
final class NewsletterHandler
{
    public function __construct(
        private readonly NewsletterService $newsletter,
        private readonly LoggerInterface $logger,
    ) {
    }
}
```

Mit `_defaults.autowire: true` loest Symfony Klassen- und Interface-Typen
automatisch auf. Explizite Definitionen bleiben sinnvoll fuer:

- Aliase
- Named Autowiring Aliases
- Scalar-Bindings
- Factories
- Public Services
- Tags
- Tagged Iterators
- Service Locators

### Aliase und Public Services

Services sind standardmaessig privat. Oeffentlich sollten nur Entry-Points sein,
die bewusst aus WordPress, Tests, CLI oder Glue-Code geholt werden.

```yaml
services:
    Acme\Demo\Contract\FormatterInterface:
        alias: Acme\Demo\Formatter\HtmlFormatter

    Acme\Demo\Admin\SettingsPage:
        public: true
```

Named Aliases passen zu `#[Target]`:

```yaml
services:
    'Acme\Demo\Contract\FormatterInterface $adminFormatter':
        alias: Acme\Demo\Formatter\HtmlFormatter

    'Acme\Demo\Contract\FormatterInterface $apiFormatter':
        alias: Acme\Demo\Formatter\JsonFormatter
```

```php
use Symfony\Component\DependencyInjection\Attribute\Target;

final class FormatterSelection
{
    public function __construct(
        #[Target('adminFormatter')]
        private readonly FormatterInterface $adminFormatter,
        #[Target('apiFormatter')]
        private readonly FormatterInterface $apiFormatter,
    ) {
    }
}
```

## Methoden- und Setter-Injection

Konstruktor-Injection bleibt bevorzugt. Setter-Injection ist fuer optionale oder
nachtraeglich konfigurierbare Abhaengigkeiten sinnvoll.

```php
use Symfony\Contracts\Service\Attribute\Required;

final class RequiredSummary
{
    private ?HtmlFormatter $formatter = null;

    #[Required]
    public function setFormatter(HtmlFormatter $formatter): void
    {
        $this->formatter = $formatter;
    }
}
```

YAML-Variante:

```yaml
services:
    Acme\Demo\Service\RequiredSummary:
        calls:
            - setFormatter: ['@Acme\Demo\Formatter\HtmlFormatter']
```

## Tags und Tagged Collections

Tags sind der wichtigste Mechanismus, um lose gekoppelte Plugin-Erweiterungen zu
modellieren.

```php
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('demo.panel')]
interface PanelInterface
{
    public function title(): string;
}
```

```php
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'primary', priority: 20)]
final class PrimaryPanel implements PanelInterface
{
    public function title(): string
    {
        return 'Primary';
    }
}
```

```php
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class PanelSummary
{
    public function __construct(
        #[AutowireIterator('demo.panel', indexAttribute: 'index')]
        private readonly iterable $panels,
    ) {
    }
}
```

YAML-Variante:

```yaml
services:
    Acme\Demo\Panel\PrimaryPanel:
        tags:
            - { name: demo.panel, index: primary, priority: 20 }

    Acme\Demo\PanelRegistry:
        arguments:
            $panels: !tagged_iterator demo.panel
```

## Service Locators

Service Locators sind fuer gezielten, lazy Zugriff auf eine kleine Menge
bekannter Services geeignet. Sie sind die saubere Alternative zu Arrays mit
Service-IDs oder zum Injizieren des ganzen Containers.

```php
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final class FormatterLocator
{
    public function __construct(
        #[AutowireLocator([
            'html' => HtmlFormatter::class,
            'json' => JsonFormatter::class,
        ])]
        private readonly ContainerInterface $formatters,
    ) {
    }
}
```

Der Kernel selbst nutzt diese Idee fuer WordPress-Hooks: `HookCompilerPass`
sammelt alle `kernel.hook`-Services und registriert sie ueber
`ServiceLocatorTagPass::register()`. Dadurch werden Hook-Services erst dann aus
dem Container geholt, wenn WordPress den Hook tatsaechlich ausfuehrt.

## Factories

Factories eignen sich fuer Services, deren Konstruktion WordPress-State,
Legacy-APIs oder externe SDKs einbeziehen muss.

```yaml
services:
    Acme\Demo\Calendar\CalendarService:
        factory: ['Acme\Demo\Factory\CalendarFactory', 'create']
```

Auch statische Factories und Service-Methoden sind moeglich:

```yaml
services:
    Acme\Demo\EventSystem:
        factory: ['Acme\Demo\EventSystem', 'getInstance']
        public: true

    Acme\Demo\EventDispatcher:
        factory: ['@Acme\Demo\EventSystem', 'getDispatcher']
```

## Lazy Services und Service Closures

Teure Integrationen sollten lazy sein, damit WordPress-Requests nicht unnoetig
Objekte materialisieren.

```php
use Symfony\Component\DependencyInjection\Attribute\Lazy;

#[Lazy]
final class ExpensiveApiClient
{
}
```

Service Closures sind ebenfalls verfuegbar, wenn ein einzelner Service erst bei
Bedarf erzeugt werden soll:

```yaml
services:
    Acme\Demo\Service\ReportBuilder:
        arguments:
            $clientFactory: !service_closure '@Acme\Demo\Api\ExpensiveApiClient'
```

Im Kernel-Hook-System ist Lazy-Verhalten bereits eingebaut, weil Hook-Callbacks
Closures sind, die den eigentlichen Service erst beim Hook-Aufruf aus dem
Locator holen.

## Optionale Abhaengigkeiten

Optionale Services sollten explizit modelliert werden:

```yaml
services:
    Acme\Demo\Service\OptionalIntegration:
        arguments:
            $logger: '@?logger'
```

Bei Methodenaufrufen kann Symfony fehlende optionale Services ignorieren:

```yaml
services:
    Acme\Demo\Service\OptionalIntegration:
        calls:
            - setProfiler: ['@?Acme\Demo\Profiler\Profiler']
```

Fuer Kernellogik gilt: ein fehlender optionaler WordPress-Service sollte nicht
den gesamten Kernel-Boot abbrechen. Bei notwendigen Services sollte der Fehler
dagegen frueh im Compile-Schritt sichtbar werden.

## Nicht geteilte Services

Symfony-Services sind standardmaessig shared. Fuer zustandsbehaftete Objekte,
die pro Zugriff neu erzeugt werden muessen, kann `shared: false` gesetzt werden.

```yaml
services:
    Acme\Demo\RequestScoped\TemporaryBuffer:
        shared: false
```

Das sollte im WordPress-Kontext sparsam eingesetzt werden. Viele Services leben
nur fuer einen Request; zusaetzliche Instanzen machen Debugging und Hook-Verhalten
schnell unuebersichtlich.

## Parent Services

Parent Services koennen gemeinsame Definitionsteile buendeln:

```yaml
services:
    Acme\Demo\AbstractWebhookHandler:
        abstract: true
        arguments:
            $logger: '@logger'

    Acme\Demo\StripeWebhookHandler:
        parent: Acme\Demo\AbstractWebhookHandler
        arguments:
            $topic: 'stripe'
```

Der Kernel kompiliert den Container vollstaendig. Damit stehen Symfony-Features
wie Parent Services zur Verfuegung, solange die verwendeten Loader und
Definitionen sie unterstuetzen.

## Service Decoration

Service Decoration ist geeignet, wenn ein Bundle Verhalten eines anderen
Services erweitern moechte, ohne dessen Klasse zu ersetzen.

```yaml
services:
    Acme\Demo\TracingMailer:
        decorates: Acme\Mailer\MailerInterface
        arguments:
            $inner: '@Acme\Demo\TracingMailer.inner'
```

Im Projekt sollte Decoration gegenueber globalen WordPress-Filtern bevorzugt
werden, wenn es wirklich um Service-Verhalten geht. Fuer WordPress-Ausgabe,
Hooks und Plugin-Integrationen bleiben `kernel.hook`-Tags oft lesbarer.

## Configurators

Symfony Configurators rufen nach der Konstruktion eine Methode oder Callable auf,
um einen Service final einzurichten. Das ist nuetzlich, wenn ein Service nicht
alle Optionen sinnvoll im Konstruktor entgegennehmen kann.

```yaml
services:
    Acme\Demo\Service\ConfiguredClient:
        configurator: ['Acme\Demo\Factory\ClientConfigurator', 'configure']
```

Fuer neue Services sollte Konstruktor-Injection bevorzugt werden. Configurators
sind vor allem fuer Drittanbieterobjekte oder Legacy-Objekte sinnvoll.

## Expressions

Symfony Expressions koennen spezielle Werte aus Services, Parametern oder Env
berechnen.

```yaml
services:
    Acme\Demo\Service\ApiClient:
        arguments:
            $dsn: '@=service("Acme\\Demo\\Config\\ApiConfig").dsn()'
```

Im Kernel sollten Expressions die Ausnahme bleiben. Kleine Factory-Services sind
meist testbarer und einfacher zu debuggen.

## Core Services und synthetische Services

Der Kernel registriert vertraute Symfony-Core-Services und die notwendigen
Runtime-Bruecken fuer WordPress:

```text
kernel
service_container
parameter_bag
file_locator
reverse_container
config_cache_factory
dependency_injection.config.container_parameters_resource_checker
config.resource.self_checking_resource_checker
services_resetter
container.env_var_processor
```

Zusaetzlich nutzt der Kernel synthetische Services fuer Runtime-Objekte, die
der Container nicht selbst konstruieren darf:

```text
kernel.container
kernel.config
kernel.context
kernel.kernel
kernel.app
```

Diese IDs werden vor der Kompilierung als synthetic und public registriert und
nach dem Erzeugen des Runtime-Containers mit echten Instanzen befuellt. Aliase
machen sie per Klasse oder Interface injizierbar:

```text
Psr\Container\ContainerInterface
Symfony\Component\DependencyInjection\ContainerInterface
SymPress\Kernel\Container
SymPress\Kernel\SiteConfig
SymPress\Kernel\WpContext
SymPress\Kernel\Kernel\KernelInterface
Symfony\Component\DependencyInjection\Kernel\KernelInterface
SymPress\Kernel\App
Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface
Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface
Symfony\Component\DependencyInjection\ServicesResetterInterface
```

```php
use SymPress\Kernel\SiteConfig;
use SymPress\Kernel\WpContext;

final class ContextAwareService
{
    public function __construct(
        private readonly SiteConfig $config,
        private readonly WpContext $context,
    ) {
    }
}
```

Das entspricht dem Symfony-Konzept synthetischer Services, ist hier aber zentral
fuer die Bruecke zwischen kompiliertem Container und laufender WordPress-App.

Services, die `Symfony\Contracts\Service\ResetInterface` implementieren, werden
automatisch mit `kernel.reset` getaggt und durch `services_resetter`
zurueckgesetzt. Service Subscriber und Service Locators werden ebenfalls mit den
Symfony-Standardtags autokonfiguriert.

Zum Core gehoeren ausserdem Symfony-nahe Services und Aliases:
`filesystem`, optional `event_dispatcher`, optional `clock`,
`container.expression_language`, PSR/EventDispatcher-Aliases,
`LoggerAwareInterface`-Autoconfiguration und die ueblichen
`container.excluded`-Markierungen fuer Compiler-Passes, PHP-Attribute, Enums
und PHPUnit-TestCases.

## Compiler Passes

Der Kernel registriert eigene und Symfony-Compiler-Passes:

- `HookCompilerPass`
- `AddConsoleCommandPass`
- `AddBehaviorDescribingTagsPass`
- `ResettableServicePass`
- `MergeExtensionConfigurationPass`
- Bundle-spezifische Passes ueber `BundleInterface::build()`

`HookCompilerPass` sammelt alle Services mit dem Tag `kernel.hook`, validiert
Methode, Typ, Prioritaet und `accepted_args`, baut einen Service Locator und
setzt die Hook-Metadaten auf `HookLoader`.

```yaml
services:
    Acme\Demo\Admin\AdminMenu:
        tags:
            - { name: kernel.hook, hook: 'admin_menu', method: register }
```

Der Pass arbeitet korrekt auf Definitionen statt auf Service-Instanzen. Das ist
wichtig, weil Compiler Passes vor dem Runtime-Container laufen.

## WordPress Hooks

Hooks bleiben deklarativ und containerbasiert.

```yaml
services:
    Acme\Demo\Hook\TextdomainLoader:
        tags:
            - { name: kernel.hook, hook: 'init', method: load }

    Acme\Demo\Hook\ContentFilter:
        tags:
            - { name: kernel.hook, hook: 'the_content', method: filter, type: filter, priority: 20 }
```

Unterstuetzte Tag-Attribute:

- `hook`: WordPress-Hook-Name
- `method`: Methode, Standard ist `__invoke`
- `type`: `action` oder `filter`, Standard ist `action`
- `priority`: Standard ist `10`
- `accepted_args`: optional; wenn nicht gesetzt, wird die Anzahl der
  Methodenparameter reflektiert

Die Attribute werden beim Compile-Schritt validiert. Fehlende Methoden,
ungueltige Typen oder falsche `accepted_args` fallen damit frueh auf.

### `#[AsHook]`

Der Kernel ergaenzt Symfony um ein WordPress-spezifisches Attribut:

```php
use SymPress\Kernel\Attribute\AsHook;

#[AsHook('plugins_loaded', priority: 9, acceptedArgs: 0)]
final class PluginBootstrap
{
    public function __invoke(): void
    {
    }
}
```

Methoden-Attribut:

```php
final class AdminNotice
{
    #[AsHook('admin_notices', priority: 5)]
    public function render(): void
    {
    }
}
```

Wenn `method` auf Methodenebene nicht gesetzt ist, verwendet der Kernel den
Namen der annotierten Methode.

## Console Integration

Der Kernel registriert Symfony Console Commands ueber
`Symfony\Component\Console\Attribute\AsCommand`. Dazu wird das Attribut fuer
Autoconfiguration auf `console.command` gemappt und `AddConsoleCommandPass`
eingesetzt.

```php
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand(name: 'demo:report', description: 'Build a demo report.')]
final class DemoReportCommand extends Command
{
}
```

`WpCliConsoleBridge` registriert bei WP-CLI den Befehl:

```bash
wp console demo:report
```

Der Kernel bringt ausserdem kleine Container-Werkzeuge mit:

```bash
wp console debug:container
wp console debug:container --parameters
wp console lint:container
wp console container:dump --format=yaml
```

## Runtime Cache

Der Runtime-Container wird in `var/cache/<environment>/kernel` geschrieben.
Der Cache-Key basiert auf einem Fingerprint aus:

- Projektverzeichnis
- Environment
- Debug-Status
- Deployment-Fingerprint
- Kernel-Quellen
- Bundle-Metadaten und Bundle-Quellen
- geladene Config-Dateien

Wenn `WP_DEBUG` aktiv ist, verfolgt der Kernel Source-Hashes. In Produktion
nutzt er schnellere Zeitstempel und kann einen Build-Identifier verwenden:

```bash
SYMPRESS_KERNEL_BUILD_ID=2026-06-13T120000Z
```

Die Cache-Dateien werden mit Locking geschrieben. Dadurch wird verhindert, dass
parallele Requests denselben Container gleichzeitig dumpen.

## Uebersetzungen

Bundles koennen `Resources/translations/` bereitstellen. Der Kernel sammelt die
Pfade in `kernel.translation_paths` und registriert `TranslationLoader` als
public Service.

Unterstuetzte Dateien:

```text
*.en.xlf
*.en.xliff
```

Der Loader liest XLIFF-Dateien und gruppiert die Ergebnisse nach Bundle-Paket.

## Zugriff auf den Container vermeiden

Symfony empfiehlt, Anwendungscode nicht vom Container abhaengig zu machen. Das
gilt im Kernel besonders stark: Services sollten ihre Abhaengigkeiten ueber
Konstruktoren, Setter, Tagged Iterator oder Locators bekommen.

Erlaubte Container-Zugriffe sind Entry-Points:

- `App::make()` fuer WordPress-Glue-Code
- `HookLoader` fuer lazy Hook-Services
- kleine Service Locators fuer bewusst begrenzte Service-Mengen
- Tests und Debugging

Nicht empfohlen:

```php
final class BadExample
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }
}
```

Besser:

```php
final class GoodExample
{
    public function __construct(private readonly MailerInterface $mailer)
    {
    }
}
```

## Debugging

Der Kernel bietet kleine Entsprechungen zu Symfonys Container-Werkzeugen fuer
den Site-Container:

```bash
wp console debug:container
wp console debug:container app
wp console debug:container --parameters
wp console debug:container --env-vars
wp console debug:container --tag=kernel.hook --types
wp console debug:container app --types --show-arguments
wp console lint:container
wp console container:dump --format=yaml
wp console container:dump --format=xml
wp console container:dump --format=php
```

Weitere praktische Debug-Punkte bleiben:

- `kernel.container_configured`, um geladene Config-Dateien zu inspizieren.
- `kernel.container_ready`, um nach dem Runtime-Container zu schauen.
- `var/cache/<env>/kernel/meta.php`, um Fingerprint, Klasse und Cache-Datei zu
  pruefen.
- Der Showcase-Plugin-Screen fuer Attribute, Locators, Tags und Lazy Services.

## Abgleich mit Symfony Learn More

| Symfony-Thema | Bedeutung im Kernel |
| --- | --- |
| Compiling the Container | Bei Cache-Miss kompiliert der Kernel einen Runtime-Container und dumpt ihn mit `PhpDumper`. |
| Container Building Workflow | `App::boot()` bildet den Workflow: discover, configure, compile, dump, hydrate, register hooks. |
| Configurable Bundles | Klassische Extensions und Symfony-8.1-`BundleExtension` mit `configure()`/`loadExtension()` werden unterstuetzt. |
| Service Aliases und Public Services | Direkt unterstuetzt; public nur fuer echte Entry-Points verwenden. |
| Autowiring | Standard ueber `_defaults.autowire: true`; Resource-Scans sind empfohlen. |
| Method Calls und Setter Injection | Direkt unterstuetzt, inkl. `#[Required]`. |
| Compiler Passes | Direkt unterstuetzt; Bundles koennen Passes in `build()` registrieren. |
| Configurators | Symfony-Feature, nutzbar fuer Legacy-/Drittanbieterobjekte. |
| Debug Container | `debug:container`, `lint:container` und `container:dump` sind ueber `wp console` verfuegbar; Tag-, Type-, Argument- und Env-Var-Ansichten sind vorhanden. |
| Core Services | `parameter_bag`, `event_dispatcher`, `filesystem`, `clock`, `file_locator`, `reverse_container`, `config_cache_factory`, `services_resetter`, ExpressionLanguage und Env-Processor sind vorhanden, wenn die jeweiligen Symfony-Komponenten installiert sind. |
| Service Definition Objects | Relevant fuer Kernel, Bundles und Compiler Passes. |
| Expressions | Verfuegbar, aber sparsam einsetzen; Factories sind meist klarer. |
| Factories | Direkt nutzbar und im Projekt bereits gaengig fuer WordPress-nahe Services. |
| Imports | Der Kernel importiert automatisch bekannte Config-Muster aus Kernel, Bundles und Site. |
| Injection Types | Constructor Injection ist Standard; Setter/Property nur bewusst nutzen. |
| Lazy Services | Direkt unterstuetzt, inkl. `#[Lazy]`; Hook-Aufloesung ist lazy. |
| Optional Dependencies | Direkt nutzbar mit optionalen Referenzen. |
| Parent Services | Direkt nutzbar, weil der Container voll kompiliert wird. |
| Request Service | Kein Symfony RequestStack per Default; fuer WordPress Kontext `WpContext` injizieren. |
| Service Closures | Direkt nutzbar; Hook-Callbacks nutzen dasselbe Lazy-Prinzip. |
| Service Decoration | Direkt nutzbar und fuer Service-Erweiterung sauberer als globale Hooks. |
| Service Subscribers & Locators | Direkt nutzbar; der Kernel nutzt Service Locator fuer Hook-Services. |
| Non Shared Services | Direkt nutzbar mit `shared: false`, aber sparsam verwenden. |
| Synthetic Services | Zentral im Kernel fuer Runtime-Objekte wie App, Kernel, Config und Kontext. |
| Service Tags | Zentral: `kernel.hook`, Bundle-eigene Tags und tagged iterators. |

## Quellen

- [Symfony DependencyInjection Component](https://symfony.com/doc/current/components/dependency_injection.html)
- [Symfony Service Container](https://symfony.com/doc/current/service_container.html)
- [symfony/dependency-injection Repository](https://github.com/symfony/dependency-injection)
- [Compiling the Container](https://symfony.com/doc/current/components/dependency_injection/compilation.html)
- [Container Building Workflow](https://symfony.com/doc/current/components/dependency_injection/workflow.html)
- [Service Aliases and Public Services](https://symfony.com/doc/current/service_container/alias_private.html)
- [Autowiring](https://symfony.com/doc/current/service_container/autowiring.html)
- [Service Method Calls and Setter Injection](https://symfony.com/doc/current/service_container/calls.html)
- [Compiler Passes](https://symfony.com/doc/current/service_container/compiler_passes.html)
- [Configurators](https://symfony.com/doc/current/service_container/configurators.html)
- [Debugging the Service Container](https://symfony.com/doc/current/service_container/debug.html)
- [Service Definition Objects](https://symfony.com/doc/current/service_container/definitions.html)
- [Expression Language](https://symfony.com/doc/current/service_container/expression_language.html)
- [Factories](https://symfony.com/doc/current/service_container/factories.html)
- [Imports](https://symfony.com/doc/current/service_container/import.html)
- [Types of Injection](https://symfony.com/doc/current/service_container/injection_types.html)
- [Lazy Services](https://symfony.com/doc/current/service_container/lazy_services.html)
- [Optional Dependencies](https://symfony.com/doc/current/service_container/optional_dependencies.html)
- [Parent Services](https://symfony.com/doc/current/service_container/parent_services.html)
- [Request from the Container](https://symfony.com/doc/current/service_container/request.html)
- [Service Closures](https://symfony.com/doc/current/service_container/service_closures.html)
- [Service Decoration](https://symfony.com/doc/current/service_container/service_decoration.html)
- [Service Subscribers & Locators](https://symfony.com/doc/current/service_container/service_subscribers_locators.html)
- [Non Shared Services](https://symfony.com/doc/current/service_container/shared.html)
- [Synthetic Services](https://symfony.com/doc/current/service_container/synthetic_services.html)
- [Service Tags](https://symfony.com/doc/current/service_container/tags.html)
