## usage

`prometheusMetrics::incrementCounter('custom_counter_name', ['custom_label' => 'label_value'], 1);`

`prometheusMetrics::setGauge('custom_gauge_name', ['custom_label' => 'label_value'], 1);`

`prometheusMetrics::incrementHistogram('custom_histogram_prefix', ['custom_label' => 'label_value'], [1,2,3,4,5,10,15,20,30,40,50,60], 13);`

`prometheusMetrics::buildMetricsPage();`