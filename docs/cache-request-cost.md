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

The before/after run on PHP 8.5.9, 2026-10-02, produced:

| Measurement | Previous content default | Metadata default |
| --- | ---: | ---: |
| Warm hits | 40/40 | 40/40 |
| Hashed bytes per request | 1,288,952 | 0 |
| OPcache invalidations per request | 1 | 0 |
| Cache selection p50 / p95, ms | 1.364 / 1.648 | 0.942 / 1.006 |
| HTTP p50 / p95, ms | 1.876 / 2.518 | 1.379 / 1.439 |

These are local fixture timings, not WordPress response-time guarantees. Input
size, filesystems, PHP configuration and concurrent load affect latency.
`CacheRequestCompatibilityTest` verifies zero warm hashing/invalidation, timestamp
invalidation, explicit content-mode detection and safe migration of group-writable
cache artifacts. The metadata mode intentionally cannot detect an edit preserving
both size and mtime; clear the cache for such edits.
