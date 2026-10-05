# Cache request cost

Mutable caches use mtime/size signatures by default. Set
`SYMPRESS_KERNEL_CONTENT_HASHES=1` when detecting same-size edits with preserved
timestamps is required. Switching modes changes the cache identity. Immutable
production snapshots use `SYMPRESS_KERNEL_IMMUTABLE_CACHE=1` and a unique build ID;
every changed release input requires a changed build ID.

Run `php tools/benchmark-cache-requests.php` to measure 40 warm HTTP requests
through PHP's built-in server with OPcache enabled. The fixture has one 1 MiB PHP
configuration file and runs the real container cache hit path. The probe counts
content-hash bytes and OPcache invalidations. The server and fixture are removed
afterward; no customer application or database is touched.

The 2026-10-05 PHP 8.5.9 regression checks record zero content-hash bytes and zero
OPcache invalidations on unchanged mutable container reads. Discovery readers
also perform zero invalidations. Metadata is now JSON; compiled containers retain
generation-specific PHP filenames. A native fixture keeps the same FPM worker
alive with `opcache.validate_timestamps=0`, updates container and discovery data
from separate CLI writers, and verifies that subsequent requests select the new
generation without forcing metadata invalidation.

No timing benchmark was rerun for 1.1.5. The earlier 2026-10-02 candidate timings
did not describe the reader invalidation introduced in 1.1.4 and are not evidence
of current response latency. Filesystems, PHP configuration and concurrent load
affect latency; the fixture is not a WordPress response-time guarantee.
`CacheRequestCompatibilityTest` covers timestamp invalidation, explicit
content-mode detection, legacy PHP metadata migration, classic/read-only default
paths and safe migration of group-writable cache artifacts. Metadata mode cannot
detect an edit preserving both size and mtime; clear the cache for such edits.
