<?php

namespace mpcmf\system\prometheus;

use mpcmf\system\cache\memcached;
use mpcmf\system\configuration\config;
use mpcmf\system\configuration\environment;
use mpcmf\system\helper\io\log;
use mpcmf\system\storage\mongoInstance;

class prometheusMetrics
{

    /** TTL of gauges, refreshed by every setGauge() */
    protected const CACHE_EXPIRE = 86400;

    /**
     * Lifetime of counter keys (30 days, the largest relative memcached TTL).
     * increment() does not extend the TTL, so a counter restarts from zero once per period.
     */
    protected const COUNTER_EXPIRE = 2592000;

    /** Counters are stored as integers in millionths: increment() accepts integers only */
    protected const VALUE_SCALE = 1000000;

    /** Key prefix and registry version of the integer storage format; older float keys are left to expire */
    protected const KEY_PREFIX = 'v2:';

    protected const FORMAT_VERSION = 2;

    protected const METRIC_NAME_REGEX = '/^[a-zA-Z_:][a-zA-Z0-9_:]*$/D';

    protected const LABEL_NAME_REGEX = '/^[a-zA-Z_][a-zA-Z0-9_]*$/D';

    use log;

    public static function incrementCounter(string $key, array $labels = [], $counter = 1): void
    {
        $name = self::buildSeriesName($key, $labels);
        if ($name === null) {
            return;
        }

        self::addToCounter($key, $name, $counter);
    }

    public static function incrementCounterCached(string $key, array $labels = [], $counter = 1, $incrementAfter = 10): void
    {
        static $cached = [];
        $name = self::buildSeriesName($key, $labels);
        if ($name === null) {
            return;
        }

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

        self::addToCounter($key, $name, $counter);
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
     * @param int|float $counter
     */
    protected static function addToCounter(string $key, string $name, $counter): void
    {
        $delta = self::toFixedPoint($key, $counter);
        if ($delta === null) {
            return;
        }
        if (self::atomicIncrement(self::buildCacheKey($key, $name), $delta, self::counterExpire($key)) === true) {
            self::saveName($key, $name, 'counter');
        }
    }

    /**
     * @param mixed $value
     */
    protected static function toFixedPoint(string $key, $value): ?int
    {
        if (!is_numeric($value) || !is_finite((float)$value) || $value < 0 || $value * self::VALUE_SCALE >= PHP_INT_MAX) {
            self::logInvalid("metric {$key}: increment must be a finite non-negative number");

            return null;
        }

        return (int)round($value * self::VALUE_SCALE);
    }

    /**
     * Add $delta to an integer key: increment(), or add() + increment() when the key is missing.
     * CAS is not used on purpose: memcached-protocol backends such as dragonfly do not support it.
     *
     * @return bool|null true if this call created the key, false if the key existed, null on error
     */
    protected static function atomicIncrement(string $mcKey, int $delta, int $expire): ?bool
    {
        $memcached = self::cache()->getBackend();
        if ($memcached->increment($mcKey, $delta) !== false) {
            return false;
        }
        // false is "not found" only with RES_NOTFOUND; anything else is a backend error
        if ($memcached->getResultCode() !== \Memcached::RES_NOTFOUND) {
            self::logBackendError("increment {$mcKey}: {$memcached->getResultMessage()}");

            return null;
        }
        $created = $memcached->add($mcKey, 0, $expire);
        if (!$created && $memcached->getResultCode() !== \Memcached::RES_NOTSTORED) {
            self::logBackendError("add {$mcKey}: {$memcached->getResultMessage()}");

            return null;
        }
        if ($memcached->increment($mcKey, $delta) === false) {
            self::logBackendError("increment {$mcKey}: {$memcached->getResultMessage()}");

            return $created ?: null;
        }

        return $created;
    }

    /**
     * All keys of one metric expire at the same moment (the phase depends on the metric name),
     * so histogram buckets, _sum and _count always restart together.
     */
    protected static function counterExpire(string $family): int
    {
        return self::COUNTER_EXPIRE - (time() + crc32($family)) % self::COUNTER_EXPIRE;
    }

    protected static function logBackendError(string $message): void
    {
        static $lastLogged = 0, $suppressed = 0;
        if (time() - $lastLogged < 60) {
            $suppressed++;

            return;
        }
        $suffix = $suppressed > 0 ? " ({$suppressed} more errors suppressed)" : '';
        self::log()->addError("[prometheusMetrics] {$message}{$suffix}");
        $lastLogged = time();
        $suppressed = 0;
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

    protected static function saveName(string $key, string $name, string $type, array $extra = []): void
    {
        try {
            $newObj = [
                'key' => $key,
                'name' => $name,
                'type' => $type,
                'cache_key' => self::buildCacheKey($key, $name),
                'version' => self::FORMAT_VERSION,
                'scale' => $type === 'gauge' ? 1 : self::VALUE_SCALE,
            ] + $extra;
            self::coll()->insert($newObj);
        } catch (\MongoDuplicateKeyException $e) {
            //pass
        }
    }

    protected static function buildCacheKey(string $key, string $name): string
    {
        return self::KEY_PREFIX . "{$key}_" . md5($name);
    }

    public static function buildMetricsPage(): string
    {
        $cache = self::cache();
        $response = '';
        $metrics = [];
        foreach (self::coll()->find(['version' => self::FORMAT_VERSION]) as $metric) {
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
            $scale = (int)($metrics[$key]['scale'] ?? 1);
            $value = $scale > 1 && is_int($value) ? self::formatFixedPoint($value, $scale) : self::formatValue($value);
            $groupKey = "# TYPE {$metrics[$key]['key']} {$metrics[$key]['type']}";
            $metricGrouped[$groupKey][] = "{$metrics[$key]['name']} {$value}";
        }

        foreach ($metricGrouped as $groupKey => $groupMetrics) {
            $response .= $groupKey . "\n" . implode("\n", $groupMetrics) . "\n";
        }

        return $response;
    }

    protected static function formatFixedPoint(int $value, int $scale): string
    {
        $sign = $value < 0 ? '-' : '';
        $value = abs($value);
        $fraction = $value % $scale;
        if ($fraction === 0) {
            return $sign . intdiv($value, $scale);
        }
        $digits = strlen((string)($scale - 1));

        return $sign . intdiv($value, $scale) . '.' . rtrim(str_pad((string)$fraction, $digits, '0', STR_PAD_LEFT), '0');
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