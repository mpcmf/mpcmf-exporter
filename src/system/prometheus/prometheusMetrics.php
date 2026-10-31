<?php

namespace mpcmf\system\prometheus;

use mpcmf\system\cache\memcached;
use mpcmf\system\configuration\config;
use mpcmf\system\configuration\environment;
use mpcmf\system\helper\io\log;
use mpcmf\system\storage\mongoInstance;

class prometheusMetrics
{

    protected const CACHE_EXPIRE = 86400;

    protected const METRIC_NAME_REGEX = '/^[a-zA-Z_:][a-zA-Z0-9_:]*$/D';

    protected const LABEL_NAME_REGEX = '/^[a-zA-Z_][a-zA-Z0-9_]*$/D';

    use log;

    public static function incrementCounter(string $key, array $labels = [], $counter = 1): void
    {
        $name = self::buildSeriesName($key, $labels);
        if ($name === null) {
            return;
        }

        $cache = self::cache();
        $mcKey = self::buildCacheKey($key, $name);
        $cv = $cache->get($mcKey);
        if($cv === false) {
            $cv = $counter;
            self::saveName($key, $name, 'counter');
        } else {
            $cv += $counter;
        }

        $cache->set($mcKey, $cv, self::CACHE_EXPIRE);
    }

    public static function incrementCounterCached(string $key, array $labels = [], $counter = 1, $incrementAfter = 10): void
    {
        static $cached = [];
        $name = self::buildSeriesName($key, $labels);
        if ($name === null) {
            return;
        }

        $cache = self::cache();
        $mcKey = self::buildCacheKey($key, $name);
        if(!isset($cached[$mcKey])) {
            $cached[$mcKey] = 0;
        }
        $cached[$mcKey] += $counter;
        if($cached[$mcKey] < $incrementAfter) {
            return;
        }
        $counter = $cached[$mcKey];
        $cached[$mcKey] = 0;

        $cv = $cache->get($mcKey);
        if($cv === false) {
            $cv = $counter;
            self::saveName($key, $name, 'counter');
        } else {
            $cv += $counter;
        }

        $cache->set($mcKey, $cv, self::CACHE_EXPIRE);
    }

    public static function setGauge(string $key, array $labels = [], $value = 0): void
    {
        $name = self::buildSeriesName($key, $labels);
        if ($name === null) {
            return;
        }

        $mcKey = self::buildCacheKey($key, $name);
        $cache = self::cache();
        if($cache->get($mcKey) === false) {
            self::saveName($key, $name, 'gauge');
        }
        $cache->set($mcKey, $value, self::CACHE_EXPIRE);
    }

    public static function incrementHistogram(string $name, array $labels, array $buckets, int $value): void
    {
        $bucketName = "{$name}_bucket";
        $totalSumName = "{$name}_sum";
        $totalCountName = "{$name}_count";

        $labelsBucket = $labels;
        $activeBucket = '+Inf';
        foreach ($buckets as $bucket) {
            if($value < $bucket) {
                $activeBucket = $bucket;
                break;
            }
        }
        $labelsBucket['le'] = $activeBucket;
        self::incrementCounter($bucketName, $labelsBucket, 1);
        self::incrementCounter($totalCountName, $labels, 1);
        self::incrementCounter($totalSumName, $labels, $value);
    }

    /**
     * Validate the metric and label names and build the series name.
     * Returns null (and logs) when the series cannot be exposed: one bad line breaks the whole scrape.
     */
    protected static function buildSeriesName(string $key, array $labels): ?string
    {
        if (!preg_match(self::METRIC_NAME_REGEX, $key)) {
            self::logInvalid("invalid metric name '{$key}'");

            return null;
        }
        foreach ($labels as $label => $labelValue) {
            if (!preg_match(self::LABEL_NAME_REGEX, (string)$label)) {
                self::logInvalid("metric {$key}: invalid label name '{$label}'");

                return null;
            }
            if ($labelValue !== null && !is_scalar($labelValue) && !(is_object($labelValue) && method_exists($labelValue, '__toString'))) {
                self::logInvalid("metric {$key}: value of label {$label} is not a string");

                return null;
            }
            if (!preg_match('//u', (string)$labelValue)) {
                self::logInvalid("metric {$key}: value of label {$label} is not valid UTF-8");

                return null;
            }
        }

        return self::formatNameWithLabels($key, $labels);
    }

    protected static function logInvalid(string $message): void
    {
        static $logged = [];
        if (isset($logged[$message]) || count($logged) >= 1000) {
            return;
        }
        $logged[$message] = true;
        self::log()->addWarning("[prometheusMetrics] {$message}, metric skipped");
    }

    protected static function escapeLabelValue(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n']);
    }

    protected static function formatNameWithLabels(string $key, array $labels): string
    {
        static $systemData;
        if($systemData === null) {
            $systemData = [
                'env' => environment::getCurrentEnvironment(),
            ];
        }

        // label order must not produce a different series
        $labels = array_replace($systemData, $labels);
        ksort($labels, SORT_STRING);
        $labelData = [];
        foreach ($labels as $label => $labelValue) {
            $labelData[] = "{$label}=\"" . self::escapeLabelValue((string)$labelValue) . '"';
        }
        $labelsStr = implode(', ', $labelData);

        return "{$key}{{$labelsStr}}";
    }

    protected static function saveName(string $key, string $name, string $type): void
    {
        try {
            $newObj = [
                'key' => $key,
                'name' => $name,
                'type' => $type,
                'cache_key' => self::buildCacheKey($key, $name)
            ];
            self::coll()->insert($newObj);
        } catch (\MongoDuplicateKeyException $e) {
            //pass
        }
    }

    protected static function buildCacheKey(string $key, string $name): string
    {
        return "{$key}_" . md5($name);
    }

    public static function buildMetricsPage(): string
    {
        $cache = self::cache();
        $response = '';
        $metrics = [];
        foreach (self::coll()->find() as $metric) {
            $metrics[$metric['cache_key']] = $metric;
        }

        $cached = [];
        $chunked = array_chunk(array_keys($metrics), 5000, true);
        foreach ($chunked as $chunk) {
            $cachedChunk = $cache->getBackend()->getMulti($chunk);
            if (!is_array($cachedChunk)) {
                continue;
            }
            $cached = array_merge($cached, $cachedChunk);
        }
        unset($chunked, $chunk, $cachedChunk);

        if(empty($cached)) {

            return $response;
        }

        $metricGrouped = [];
        foreach ($cached as $key => $value) {
            $value = self::formatValue($value);
            $groupKey = "# TYPE {$metrics[$key]['key']} {$metrics[$key]['type']}";
            $metricGrouped[$groupKey][] = "{$metrics[$key]['name']} {$value}";
        }

        foreach ($metricGrouped as $groupKey => $groupMetrics) {
            $response .= $groupKey . "\n" . implode("\n", $groupMetrics) . "\n";
        }

        return $response;
    }

    /**
     * Format a sample value for the text exposition format: +Inf/-Inf/NaN, integers without ".0",
     * fractions in plain notation where the exponent is small.
     *
     * @param int|float|string|bool|null $value
     */
    protected static function formatValue($value): string
    {
        if (is_int($value)) {
            return (string)$value;
        }
        $value = (float)$value;
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? '+Inf' : '-Inf';
        }
        // shortest round-trip representation, e.g. "3.0", "0.1", "1.0E-7"
        $repr = var_export($value, true);
        $ePos = strpos($repr, 'E');
        if ($ePos !== false) {
            $exponent = (int)substr($repr, $ePos + 1);
            if (abs($exponent) > 30) {
                return $repr;
            }
            $sign = $repr[0] === '-' ? '-' : '';
            $mantissa = ltrim(substr($repr, 0, $ePos), '-');
            $digits = str_replace('.', '', $mantissa);
            $point = strpos($mantissa, '.');
            $point = ($point === false ? strlen($mantissa) : $point) + $exponent;
            if ($point <= 0) {
                $repr = '0.' . str_repeat('0', -$point) . $digits;
            } elseif ($point >= strlen($digits)) {
                $repr = $digits . str_repeat('0', $point - strlen($digits));
            } else {
                $repr = substr($digits, 0, $point) . '.' . substr($digits, $point);
            }
            $repr = $sign . $repr;
        }
        if (strpos($repr, '.') !== false) {
            $repr = rtrim(rtrim($repr, '0'), '.');
        }

        return $repr === '-0' ? '0' : $repr;
    }

    protected static function coll(): \MongoCollection
    {
        static $mongo, $config;
        if ($mongo === null) {
            $config = config::getConfig(__CLASS__)['storage'];
            $mongo = mongoInstance::factory($config['configSection']);
            $mongo->checkIndicesAuto($config);
        }

        return $mongo->getCollection($config['db'], $config['collection']);
    }

    protected static function cache(): memcached
    {
        static $cache;
        if ($cache === null) {
            $section = config::getConfig(__CLASS__)['cache']['configSection'];
            $cache = memcached::factory($section);
        }

        return $cache;
    }
}