# Native compile-time environment diagnostics

Symfony 8.2's `resolvesAtCompileTime()` configuration node API and native
`MergeExtensionConfigurationPass` log explain which environment variables were
inlined and which option or extension requested them. These APIs are not yet
stable as of 2026-10-09. Kernel continues to require stable DI `^8.1.8` and does
not introduce a development dependency or copy the upstream implementation.

The existing Kernel `debug:container --env-vars` command lists remaining
`%env(...)%` references. It does not identify values consumed at compile time.
With Framework Bundle, the framework command of the same name uses Symfony's
native descriptors. They are distinct implementations; no second descriptor or
compile-time resolver is introduced here.

For a configured `ContainerBuilder` in an explicit development build, read
`$builder->getCompiler()->getLog()` after compilation. Select only the native
`Inlined env var` records and output their variable name and option/extension
identifier. Do not print the entire compiler log or resolved parameter values:
other compiler passes can log arbitrary application data.

```php
foreach ($builder->getCompiler()->getLog() as $line) {
    if (preg_match('/Inlined env var "%env\(([A-Za-z0-9_:]+)\)%" (into option|while loading extension) "([A-Za-z0-9_.-]+)"\./', $line, $matches) === 1) {
        printf("%s\t%s=%s\n", $matches[1], $matches[2], $matches[3]);
    }
}
```

`php tools/check-compile-time-env.php` runs a synthetic native configuration probe
and prints only the variable and configuration path. On stable 8.1 it exits with
code 2 and an explicit unavailable-API message. The probe has been verified with
the upstream 8.2 Config/DI source; it does not certify a stable 8.2 release.

Kernel currently does not mark its own configuration nodes with
`resolvesAtCompileTime()`. These records therefore become relevant when a
consumer's extension opts into the new native API. Existing cached PHP containers
do not retain a complete compiler log; inspect an explicit fresh build, rather
than rebuilding during normal requests just to collect diagnostics.

Sources: [API rename](https://github.com/symfony/symfony/pull/66642),
[native compiler log](https://github.com/symfony/symfony/pull/66717).
