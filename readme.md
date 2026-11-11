## usage

`prometheusMetrics::incrementCounter('custom_counter_name', ['custom_label' => 'label_value'], 1);`

`prometheusMetrics::incrementCounterCached('custom_counter_name', ['custom_label' => 'label_value'], 1, 10);` — buffered in the process, written when the buffered value reaches the threshold, every 10 seconds (on the next call) and at shutdown; `prometheusMetrics::flushCachedCounters()` writes the buffer explicitly.

`prometheusMetrics::setGauge('custom_gauge_name', ['custom_label' => 'label_value'], 1);`

`prometheusMetrics::incrementHistogram('custom_histogram_prefix', ['custom_label' => 'label_value'], [1,2,3,4,5,10,15,20,30,40,50,60], 13);` — observations may be floats, e.g. `0.25` with buckets `[0.1, 0.5, 1]`.

`prometheusMetrics::buildMetricsPage();` — serve it with `Content-Type: text/plain; version=0.0.4`. The page always ends with `mpcmf_exporter_scrape_error{env="..."}`: `1` when values could not be read from memcached (the page is then incomplete or holds only this line, the error is logged), `0` otherwise; alert on it, the HTTP status stays the caller's. A registry (Mongo) failure throws, as before.

## notes

- Metric names must match `[a-zA-Z_:][a-zA-Z0-9_:]*`, label names `[a-zA-Z_][a-zA-Z0-9_]*`, label values must be valid UTF-8; invalid series are logged and skipped.
- Counters and histograms are incremented atomically and stored as integers in millionths (6 decimal places) under `v2:` keys; the series registry is the configured collection with the suffix `_v2` (e.g. `prometheus.metrics_v2`), so an older version of the library, which reads the configured collection, never sees them.
- Counter keys live 30 days and then restart from zero (a counter reset for `rate()`); gauges expire 24 hours after the last update.
- One name used with different types (e.g. `setGauge()` and `incrementCounter()` with different labels) is exposed as one `untyped` family with all its series, and a warning is logged once per process. The same name with the same labels shares one key: do not mix types on one series.
- Counters hold at most `PHP_INT_MAX` millionths, about 9.22e12 per key lifetime (30 days). Beyond that dragonfly rejects the increment (logged, the value stops growing until the key expires); plain memcached wraps around. Count large quantities in larger units (e.g. kilobytes instead of bytes).
- All keys of a metric expire together, but memcached/dragonfly may evict single keys under memory pressure. An evicted histogram `_count` hides the series; when it is recreated while its buckets survive, the buckets exceed `_count` until the metric's keys expire. Keep the cache below its memory limit.
- A forked child that frees its inherited memcached connection closes it for the parent too. The next request of the parent fails with a broken pipe and is repeated once on a new connection; increments are repeated only when the failure is a write failure, i.e. the request did not reach the server.

## transition from format 1 to 2

Format 1 is the format of master (keys `<key>_<md5>`, `get` + `set`, TTL 24 h, registry `prometheus.metrics`, non-cumulative buckets), format 2 is described above. Two config keys of `mpcmf\system\prometheus\prometheusMetrics` control the transition:

- `format` — `1` (default): `buildMetricsPage()` builds the master page from the format 1 keys and registry; `2`: the format 2 page.
- `write_legacy` — `true` (default): every write also goes to format 1 exactly as master does it; `false`: format 2 only.

Steps:

1. Roll out this version with `format = 1`, `write_legacy = true`. Old and new processes write format 1 together, every `/metrics` page shows full sums (format 1 is not atomic: concurrent increments are lost as on master); format 2 counts in parallel since each process update.
2. When no process runs the old version, set `format = 2` on the processes that serve `/metrics`. Counters reset once (`rate()` handles it); histograms become `histogram` type with cumulative buckets and inclusive `le`, update dashboards and alerts at this step. Rollback: `format = 1`.
3. Set `write_legacy = false` on all processes. Format 1 keys expire in 24 h, `prometheus.metrics` can be dropped afterwards.

While `write_legacy` is on, a counter increment makes 3 memcached requests instead of 1, a gauge 3 instead of 1, a histogram observation 9 instead of 3. A failed write of one format is logged and does not affect the other. Both keys are read once per process, so a change applies after a restart.

Set the keys in the application's `config.default.d/mpcmf_system_prometheus_prometheusMetrics.php`: that file replaces the package file entirely, so it must also contain `storage` and `cache`; a file without the new keys gets the defaults (`1`, `true`). Environment-specific values go through `config::setConfig(__FILE__, [...], '<environment>')` in the same file, with the full config for that environment.
