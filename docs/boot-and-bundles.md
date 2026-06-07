# Boot and Bundles

## MU-Bootstrap

The global kernel is booted through an MU plugin:

```php
<?php

declare(strict_types=1);

use SymPress\Kernel\App;
use SymPress\Kernel\Kernel\SiteKernel;

App::bootKernel(new SiteKernel(dirname(__DIR__, 2)));
```

After that, plugins and themes no longer boot **their own container**. They only provide bundle metadata, code, and `config/`.

## Bundle Discovery

A bundle is registered through `composer.json > extra.kernel`:

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

Discovery order:

1. MU-Plugins
2. Plugins
3. Theme
4. Site root config as the final override layer

## Bundle Class

The bundle class intentionally stays small:

```php
<?php

declare(strict_types=1);

namespace SymPress\Project;

use SymPress\Kernel\Bundle\AbstractBundle;

final class ProjectBundle extends AbstractBundle
{
}
```

Only override `build(ContainerBuilder $container)` when you need compiler passes or container customization.
