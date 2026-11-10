## usage

`prometheusMetrics::incrementCounter('custom_counter_name', ['custom_label' => 'label_value'], 1);`

`prometheusMetrics::incrementCounterCached('custom_counter_name', ['custom_label' => 'label_value'], 1, 10);` — buffered in the process, written when the buffered value reaches the threshold, every 10 seconds (on the next call) and at shutdown; `prometheusMetrics::flushCachedCounters()` writes the buffer explicitly.

`prometheusMetrics::setGauge('custom_gauge_name', ['custom_label' => 'label_value'], 1);`

`prometheusMetrics::incrementHistogram('custom_histogram_prefix', ['custom_label' => 'label_value'], [1,2,3,4,5,10,15,20,30,40,50,60], 13);` — observations may be floats, e.g. `0.25` with buckets `[0.1, 0.5, 1]`.

`prometheusMetrics::buildMetricsPage();` — serve it with `Content-Type: text/plain; version=0.0.4`.

## notes

- Metric names must match `[a-zA-Z_:][a-zA-Z0-9_:]*`, label names `[a-zA-Z_][a-zA-Z0-9_]*`, label values must be valid UTF-8; invalid series are logged and skipped.
- Counters and histograms are incremented atomically and stored as integers in millionths (6 decimal places) under `v2:` keys; the series registry is the configured collection with the suffix `_v2` (e.g. `prometheus.metrics_v2`), so an older version of the library, which reads the configured collection, never sees them.
- Counter keys live 30 days and then restart from zero (a counter reset for `rate()`); gauges expire 24 hours after the last update.
