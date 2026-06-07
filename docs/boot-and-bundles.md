# Boot And Bundles

## MU-Bootstrap

Der globale Kernel wird per MU-Plugin gebootet:

```php
<?php

declare(strict_types=1);

use SymPress\Kernel\App;
use SymPress\Kernel\Kernel\SiteKernel;

App::bootKernel(new SiteKernel(dirname(__DIR__, 2)));
```

Danach booten Plugins und Themes **keinen eigenen Container** mehr. Sie liefern nur noch Bundle-Metadaten, Code und `config/`.

## Bundle Discovery

Ein Bundle wird über `composer.json > extra.kernel` registriert:

```json
{
  "type": "wordpress-plugin",
  "extra": {
    "kernel": {
      "bundle": "SymPress\\Project\\ProjectBundle",
      "entry": "project/sympress-project.php"
    }
  }
}
```

Discovery-Reihenfolge:

1. MU-Plugins
2. Plugins
3. Theme
4. Website-Root-Config als letzte Override-Schicht

## Bundle-Klasse

Die Bundle-Klasse bleibt bewusst klein:

```php
<?php

declare(strict_types=1);

namespace SymPress\Project;

use SymPress\Kernel\Bundle\AbstractBundle;

final class ProjectBundle extends AbstractBundle
{
}
```

Nur wenn du Compiler-Passes oder Container-Anpassungen brauchst, überschreibst du `build(ContainerBuilder $container)`.
