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

    /** Max age of values buffered by incrementCounterCached(), seconds */
    protected const CACHED_FLUSH_INTERVAL = 10;

    use log;

    /** @var array series cache key => [metric, series name, buffered value] */
    protected static $cachedCounters = [];

    /** @var int|null pid that owns $cachedCounters */
    protected static $cachedCountersPid;

    protected static $cachedCountersFlushedAt = 0;

    public static function incrementCounter(string $key, array $labels = [], $counter = 1): void
    {
        $name = self::buildSeriesName($key, $labels);
        if ($name === null) {
            return;
        }

        self::addToCounter($key, $name, $counter);
    }

    /**
     * Buffer increments in the process and write them once the buffered value reaches $incrementAfter,
     * at least every CACHED_FLUSH_INTERVAL seconds (checked on the next call) and at process shutdown.
     */
    public static function incrementCounterCached(string $key, array $labels = [], $counter = 1, $incrementAfter = 10): void
    {
        $name = self::buildSeriesName($key, $labels);
        if ($name === null) {
            return;
        }
        self::resetCachedCountersAfterFork();

        $mcKey = self::buildCacheKey($key, $name);
        if (!isset(self::$cachedCounters[$mcKey])) {
            self::$cachedCounters[$mcKey] = [$key, $name, 0];
        }
        self::$cachedCounters[$mcKey][2] += $counter;
        if (self::$cachedCounters[$mcKey][2] >= $incrementAfter) {
            $value = self::$cachedCounters[$mcKey][2];
            unset(self::$cachedCounters[$mcKey]);
            self::addToCounter($key, $name, $value);
        }
        if (time() - self::$cachedCountersFlushedAt >= self::CACHED_FLUSH_INTERVAL) {
            self::flushCachedCounters();
        }
    }

    /**
     * Write all values buffered by incrementCounterCached(); also runs at process shutdown.
     */
    public static function flushCachedCounters(): void
    {
        self::resetCachedCountersAfterFork();
        $counters = self::$cachedCounters;
        self::$cachedCounters = [];
        self::$cachedCountersFlushedAt = time();
        foreach ($counters as [$key, $name, $value]) {
            self::addToCounter($key, $name, $value);
        }
    }

    protected static function resetCachedCountersAfterFork(): void
    {
        $pid = getmypid();
        if (self::$cachedCountersPid === $pid) {
            return;
        }
        // a forked child must not write what its parent buffered: the parent flushes it itself
        self::$cachedCounters = [];
        self::$cachedCountersFlushedAt = time();
        if (self::$cachedCountersPid === null) {
            // inherited by forked children, where it flushes only their own values
            register_shutdown_function(static function () {
                try {
                    self::flushCachedCounters();
                } catch (\Throwable $e) {
                    self::log()->addError('[prometheusMetrics] flush of cached counters failed: ' . $e->getMessage());
                }
            });
        }
        self::$cachedCountersPid = $pid;
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

    /**
     * Observe a value: one write to its bucket (le is inclusive), plus _count and _sum.
     * Buckets are made cumulative in buildMetricsPage().
     *
     * @param int|float $value
     */
    public static function incrementHistogram(string $name, array $labels, array $buckets, $value): void
    {
        $countName = self::buildSeriesName("{$name}_count", $labels);
        if ($countName === null) {
            return;
        }
        if (array_key_exists('le', $labels)) {
            self::logInvalid("histogram {$name}: label le is reserved");

            return;
        }
        $sumDelta = self::toFixedPoint($name, $value);
        $buckets = self::normalizeBuckets($name, $buckets);
        if ($sumDelta === null || $buckets === null) {
            return;
        }

        $activeBucket = '+Inf';
        foreach ($buckets as $bucket) {
            if ($value <= $bucket) {
                $activeBucket = self::formatValue($bucket);
                break;
            }
        }

        $expire = self::counterExpire($name);
        $countKey = self::buildCacheKey("{$name}_count", $countName);
        if (self::atomicIncrement($countKey, self::VALUE_SCALE, $expire) === true) {
            self::registerHistogram($name, $labels, $buckets, $countKey);
        }

        $bucketLabels = $labels;
        $bucketLabels['le'] = $activeBucket;
        $bucketName = self::formatNameWithLabels("{$name}_bucket", $bucketLabels);
        if (self::atomicIncrement(self::buildCacheKey("{$name}_bucket", $bucketName), self::VALUE_SCALE, $expire) === true) {
            // covers buckets added to an existing histogram
            self::saveHistogramPart($name, 'bucket', $bucketLabels, $countKey, $activeBucket);
        }

        $sumName = self::formatNameWithLabels("{$name}_sum", $labels);
        self::atomicIncrement(self::buildCacheKey("{$name}_sum", $sumName), $sumDelta, $expire);
    }

    /**
     * @return array|null bucket bounds sorted ascending, without +Inf
     */
    protected static function normalizeBuckets(string $name, array $buckets): ?array
    {
        $result = [];
        foreach ($buckets as $bucket) {
            if (!is_numeric($bucket) || is_nan((float)$bucket)) {
                self::logInvalid("histogram {$name}: bucket bounds must be numbers");

                return null;
            }
            $bucket += 0;
            if (!is_infinite((float)$bucket)) {
                $result[self::formatValue($bucket)] = $bucket;
            }
        }
        usort($result, static function ($a, $b) {
            return $a <=> $b;
        });

        return $result;
    }

    /**
     * Register every bucket of a new series, so unobserved buckets are exposed as well.
     */
    protected static function registerHistogram(string $name, array $labels, array $buckets, string $countKey): void
    {
        $bounds = [];
        foreach ($buckets as $bucket) {
            $bounds[] = self::formatValue($bucket);
        }
        $bounds[] = '+Inf';
        foreach ($bounds as $le) {
            $bucketLabels = $labels;
            $bucketLabels['le'] = $le;
            self::saveHistogramPart($name, 'bucket', $bucketLabels, $countKey, $le);
        }
        self::saveHistogramPart($name, 'sum', $labels, $countKey);
        self::saveHistogramPart($name, 'count', $labels, $countKey);
    }

    protected static function saveHistogramPart(string $name, string $part, array $labels, string $countKey, ?string $le = null): void
    {
        $key = "{$name}_{$part}";
        $extra = ['family' => $name, 'part' => $part, 'group' => $countKey];
        if ($le !== null) {
            $extra['le'] = $le;
        }
        self::saveName($key, self::formatNameWithLabels($key, $labels), 'histogram', $extra);
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

        $families = [];
        $histograms = [];
        foreach ($metrics as $cacheKey => $metric) {
            if ($metric['type'] === 'histogram') {
                $histograms[$metric['family']][$metric['group']][] = $metric;
                continue;
            }
            if (array_key_exists($cacheKey, $cached)) {
                $line = $metric['name'] . ' ' . self::formatStoredValue($cached[$cacheKey], $metric);
                self::addFamilyLines($families, $metric['key'], $metric['type'], [$line]);
            }
        }
        foreach ($histograms as $family => $groups) {
            foreach ($groups as $countKey => $parts) {
                // _count expired: the whole series restarts, buckets must not outlive it
                if (!array_key_exists($countKey, $cached)) {
                    continue;
                }
                self::addFamilyLines($families, $family, 'histogram', self::buildHistogramLines($parts, $cached));
            }
        }
        ksort($families, SORT_STRING);

        foreach ($families as $family => $data) {
            $response .= "# TYPE {$family} {$data['type']}\n" . implode("\n", $data['lines']) . "\n";
        }

        return $response;
    }

    /**
     * Cumulative buckets sorted by le, +Inf equal to _count, then _sum and _count.
     */
    protected static function buildHistogramLines(array $parts, array $cached): array
    {
        $buckets = [];
        $infName = $sumLine = $countLine = $count = null;
        foreach ($parts as $part) {
            $value = $cached[$part['cache_key']] ?? 0;
            if ($part['part'] === 'count') {
                $count = self::formatStoredValue($value, $part);
                $countLine = $part['name'];
            } elseif ($part['part'] === 'sum') {
                $sumLine = $part['name'] . ' ' . self::formatStoredValue($value, $part);
            } elseif ($part['le'] === '+Inf') {
                $infName = $part['name'];
            } else {
                $buckets[] = [(float)$part['le'], $part['name'], (int)$value, (int)($part['scale'] ?? 1)];
            }
        }
        usort($buckets, static function ($a, $b) {
            return $a[0] <=> $b[0];
        });

        $lines = [];
        $cumulative = 0;
        foreach ($buckets as [$le, $name, $value, $scale]) {
            $cumulative += $value;
            $lines[] = "{$name} " . self::formatFixedPoint($cumulative, $scale);
        }
        if ($infName !== null) {
            $lines[] = "{$infName} {$count}";
        }
        if ($sumLine !== null) {
            $lines[] = $sumLine;
        }
        if ($countLine !== null) {
            $lines[] = "{$countLine} {$count}";
        }

        return $lines;
    }

    protected static function addFamilyLines(array &$families, string $family, string $type, array $lines): void
    {
        if (!isset($families[$family])) {
            $families[$family] = ['type' => $type, 'lines' => []];
        } elseif ($families[$family]['type'] !== $type) {
            self::logInvalid("metric {$family} is registered as both {$families[$family]['type']} and {$type}");

            return;
        }
        array_push($families[$family]['lines'], ...$lines);
    }

    /**
     * @param mixed $value
     */
    protected static function formatStoredValue($value, $metric): string
    {
        $scale = (int)($metric['scale'] ?? 1);

        return $scale > 1 && is_int($value) ? self::formatFixedPoint($value, $scale) : self::formatValue($value);
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