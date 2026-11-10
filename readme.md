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
