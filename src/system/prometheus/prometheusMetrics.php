<?php

namespace mpcmf\system\prometheus;

use mpcmf\system\cache\memcached;
use mpcmf\system\configuration\config;
use mpcmf\system\configuration\environment;
use mpcmf\system\helper\io\log;
use mpcmf\system\storage\mongoInstance;

class prometheusMetrics
{

    /**
     * TTL of gauges, seconds
     */
    protected const CACHE_EXPIRE = 86400;

    /**
     * TTL of counter keys, not extended by increment()
     */
    protected const COUNTER_EXPIRE = 2592000;

    /**
     * Counters are stored as integers in millionths
     */
    protected const VALUE_SCALE = 1000000;

    /**
     * Key prefix of the integer storage format
     */
    protected const KEY_PREFIX = 'v2:';

    protected const FORMAT_VERSION = 2;

    /**
     * Format of the master branch: get + set floats, no key prefix
     */
    protected const LEGACY_FORMAT_VERSION = 1;

    /**
     * TTL of format 1 keys, seconds
     */
    protected const LEGACY_EXPIRE = 86400;

    /**
     * Page format if the config has no key format
     */
    protected const DEFAULT_PAGE_FORMAT = 1;

    /**
     * Format 1 writes if the config has no key write_legacy
     */
    protected const DEFAULT_WRITE_LEGACY = true;

    /**
     * Registry collection suffix of this format
     */
    protected const REGISTRY_SUFFIX = '_v2';

    protected const METRIC_NAME_REGEX = '/^[a-zA-Z_:][a-zA-Z0-9_:]*$/D';

    protected const LABEL_NAME_REGEX = '/^[a-zA-Z_][a-zA-Z0-9_]*$/D';

    /**
     * Max age of values buffered by incrementCounterCached(), seconds
     */
    protected const CACHED_FLUSH_INTERVAL = 10;

    /**
     * Lifetime of a registration marker, seconds
     */
    protected const REGISTRATION_EXPIRE = 86400;

    /**
     * Delay before a failed registration is retried, seconds
     */
    protected const REGISTRATION_RETRY_INTERVAL = 30;

    /**
     * Gauge set to 1 when the values could not be read
     */
    protected const SCRAPE_ERROR_METRIC = 'mpcmf_exporter_scrape_error';

    /**
     * errno of a write to a closed connection (EPIPE)
     */
    protected const ERRNO_BROKEN_PIPE = 32;

    use log;

    /**
     * Buffered increments: series cache key => [metric, series name, value]
     *
     * @var array
     */
    protected static $cachedCounters = [];

    /**
     * Pid that owns the buffered increments
     *
     * @var int|null
     */
    protected static $cachedCountersPid;

    protected static $cachedCountersFlushedAt = 0;

    /**
     * Registered series: value key => known until
     *
     * @var array
     */
    protected static $registered = [];

    /**
     * Next registration attempt by value key
     *
     * @var array
     */
    protected static $registrationRetryAt = [];

    /**
     * Next format 1 write of an unregistered key
     *
     * @var array
     */
    protected static $legacyRetryAt = [];

    public static function incrementCounter(string $key, array $labels = [], $counter = 1): void
    {
        $name = self::buildSeriesName($key, $labels);
        if ($name === null) {
            return;
        }

        self::addToCounter($key, $name, $counter, [[$labels, $counter]]);
    }

    /**
     * Buffer increments, write them by threshold, by time and at shutdown
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
            self::$cachedCounters[$mcKey] = [$key, $name, 0, []];
        }
        self::$cachedCounters[$mcKey][2] += $counter;
        if (self::isLegacyWritten()) {
            // format 1 series depend on the label order
            $legacyName = self::formatLegacyName($key, $labels);
            $part = self::$cachedCounters[$mcKey][3][$legacyName] ?? [$labels, 0];
            $part[1] += $counter;
            self::$cachedCounters[$mcKey][3][$legacyName] = $part;
        }
        if (self::$cachedCounters[$mcKey][2] >= $incrementAfter) {
            [, , $value, $legacy] = self::$cachedCounters[$mcKey];
            unset(self::$cachedCounters[$mcKey]);
            self::addToCounter($key, $name, $value, $legacy);
        }
        if (time() - self::$cachedCountersFlushedAt >= self::CACHED_FLUSH_INTERVAL) {
            self::flushCachedCounters();
        }
    }

    /**
     * Write all buffered increments
     */
    public static function flushCachedCounters(): void
    {
        self::resetCachedCountersAfterFork();
        $counters = self::$cachedCounters;
        self::$cachedCounters = [];
        self::$cachedCountersFlushedAt = time();
        foreach ($counters as [$key, $name, $value, $legacy]) {
            self::addToCounter($key, $name, $value, $legacy);
        }
    }

    protected static function resetCachedCountersAfterFork(): void
    {
        $pid = getmypid();
        if (self::$cachedCountersPid === $pid) {
            return;
        }
        // a child must not write what its parent buffered
        self::$cachedCounters = [];
        self::$cachedCountersFlushedAt = time();
        if (self::$cachedCountersPid === null) {
            // inherited by forked children
            register_shutdown_function(static function () {
                try {
                    self::flushCachedCounters();
                } catch (\Throwable $e) {
                    self::log()->addError('Flush of cached counters failed: ' . $e->getMessage(), [__CLASS__]);
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

        self::legacySetGauge($key, $labels, $value);

        $mcKey = self::buildCacheKey($key, $name);
        $memcached = self::cache()->getBackend();
        if (!self::backendCall($memcached, true, 'set', $mcKey, $value, self::CACHE_EXPIRE)) {
            self::logBackendError("set {$mcKey}: {$memcached->getResultMessage()}");
        }
        self::ensureRegistered($mcKey, static function () use ($key, $name) {
            return self::saveName($key, $name, 'gauge');
        });
    }

    /**
     * Observe a value: its bucket, _count and _sum
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
        $bounds = self::normalizeBuckets($name, $buckets);
        if ($sumDelta === null || $bounds === null) {
            return;
        }
        self::legacyHistogram($name, $labels, $buckets, $value);

        $activeBucket = '+Inf';
        foreach ($bounds as $bucket) {
            if ($value <= $bucket) {
                $activeBucket = self::formatValue($bucket);
                break;
            }
        }

        $expire = self::counterExpire($name);
        $countKey = self::buildCacheKey("{$name}_count", $countName);
        self::atomicIncrement($countKey, self::VALUE_SCALE, $expire);
        self::ensureRegistered($countKey, static function () use ($name, $labels, $bounds, $countKey) {
            return self::registerHistogram($name, $labels, $bounds, $countKey);
        });

        $bucketLabels = $labels;
        $bucketLabels['le'] = $activeBucket;
        $bucketName = self::formatNameWithLabels("{$name}_bucket", $bucketLabels);
        $bucketKey = self::buildCacheKey("{$name}_bucket", $bucketName);
        self::atomicIncrement($bucketKey, self::VALUE_SCALE, $expire);
        // covers buckets added to an existing histogram
        self::ensureRegistered($bucketKey, static function () use ($name, $bucketLabels, $countKey, $activeBucket) {
            return self::saveHistogramPart($name, 'bucket', $bucketLabels, $countKey, $activeBucket);
        });

        $sumName = self::formatNameWithLabels("{$name}_sum", $labels);
        self::atomicIncrement(self::buildCacheKey("{$name}_sum", $sumName), $sumDelta, $expire);
    }

    /**
     * Bucket bounds sorted ascending, without +Inf
     *
     * @return array|null
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
     * Register every bucket of a new series
     */
    protected static function registerHistogram(string $name, array $labels, array $buckets, string $countKey): bool
    {
        $bounds = [];
        foreach ($buckets as $bucket) {
            $bounds[] = self::formatValue($bucket);
        }
        $bounds[] = '+Inf';
        foreach ($bounds as $le) {
            $bucketLabels = $labels;
            $bucketLabels['le'] = $le;
            if (!self::saveHistogramPart($name, 'bucket', $bucketLabels, $countKey, $le)) {
                return false;
            }
        }

        return self::saveHistogramPart($name, 'sum', $labels, $countKey)
            && self::saveHistogramPart($name, 'count', $labels, $countKey);
    }

    protected static function saveHistogramPart(string $name, string $part, array $labels, string $countKey, ?string $le = null): bool
    {
        $key = "{$name}_{$part}";
        $extra = ['family' => $name, 'part' => $part, 'group' => $countKey];
        if ($le !== null) {
            $extra['le'] = $le;
        }

        return self::saveName($key, self::formatNameWithLabels($key, $labels), 'histogram', $extra);
    }

    /**
     * @param int|float $counter
     * @param array $legacy format 1 parts: [labels, value]
     */
    protected static function addToCounter(string $key, string $name, $counter, array $legacy): void
    {
        $delta = self::toFixedPoint($key, $counter);
        if ($delta === null) {
            return;
        }
        foreach ($legacy as [$labels, $value]) {
            self::legacyIncrement($key, $labels, $value);
        }
        $mcKey = self::buildCacheKey($key, $name);
        self::atomicIncrement($mcKey, $delta, self::counterExpire($key));
        self::ensureRegistered($mcKey, static function () use ($key, $name) {
            return self::saveName($key, $name, 'counter');
        });
    }

    /**
     * Register a series once per REGISTRATION_EXPIRE by a marker key
     */
    protected static function ensureRegistered(string $mcKey, callable $register): void
    {
        $now = time();
        if ((self::$registered[$mcKey] ?? 0) > $now || (self::$registrationRetryAt[$mcKey] ?? 0) > $now) {
            return;
        }
        $memcached = self::cache()->getBackend();
        $markerKey = self::KEY_PREFIX . 'reg:' . substr($mcKey, strlen(self::KEY_PREFIX));
        // a repeated add() would skip the registration
        if (!self::backendCall($memcached, false, 'add', $markerKey, 1, self::REGISTRATION_EXPIRE)) {
            if ($memcached->getResultCode() === \Memcached::RES_NOTSTORED) {
                // registered by another process or call
                self::$registered[$mcKey] = $now + self::REGISTRATION_EXPIRE;
                unset(self::$registrationRetryAt[$mcKey]);

                return;
            }
            self::logBackendError("add {$markerKey}: {$memcached->getResultMessage()}");
            self::$registrationRetryAt[$mcKey] = $now + self::REGISTRATION_RETRY_INTERVAL;

            return;
        }
        if ($register()) {
            self::$registered[$mcKey] = $now + self::REGISTRATION_EXPIRE;
            unset(self::$registrationRetryAt[$mcKey]);

            return;
        }
        self::backendCall($memcached, true, 'delete', $markerKey);
        self::$registrationRetryAt[$mcKey] = $now + self::REGISTRATION_RETRY_INTERVAL;
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
     * Add $delta to an integer key without CAS
     *
     * @return bool|null true if created, null on error
     */
    protected static function atomicIncrement(string $mcKey, int $delta, int $expire): ?bool
    {
        $memcached = self::cache()->getBackend();
        if (self::backendCall($memcached, false, 'increment', $mcKey, $delta) !== false) {
            return false;
        }
        // false without RES_NOTFOUND is a backend error
        if ($memcached->getResultCode() !== \Memcached::RES_NOTFOUND) {
            self::logBackendError("increment {$mcKey}: {$memcached->getResultMessage()}");

            return null;
        }
        // a repeated add() of 0 is harmless
        $created = self::backendCall($memcached, true, 'add', $mcKey, 0, $expire);
        if (!$created && $memcached->getResultCode() !== \Memcached::RES_NOTSTORED) {
            self::logBackendError("add {$mcKey}: {$memcached->getResultMessage()}");

            return null;
        }
        if (self::backendCall($memcached, false, 'increment', $mcKey, $delta) === false) {
            self::logBackendError("increment {$mcKey}: {$memcached->getResultMessage()}");

            return $created ?: null;
        }

        return $created;
    }

    /**
     * Call the backend, once more after a broken connection
     *
     * @return mixed
     */
    protected static function backendCall(\Memcached $memcached, bool $idempotent, string $method, ...$arguments)
    {
        $result = $memcached->{$method}(...$arguments);
        if ($result === false && self::isBrokenConnection($memcached, $idempotent)) {
            $result = $memcached->{$method}(...$arguments);
        }

        return $result;
    }

    protected static function isBrokenConnection(\Memcached $memcached, bool $anyConnectionError): bool
    {
        $code = $memcached->getResultCode();
        if ($code === \Memcached::RES_WRITE_FAILURE) {
            return true;
        }
        if ($code === \Memcached::RES_ERRNO && method_exists($memcached, 'getLastErrorErrno')
            && $memcached->getLastErrorErrno() === self::ERRNO_BROKEN_PIPE) {
            return true;
        }
        if (!$anyConnectionError) {
            return false;
        }

        return in_array($code, [
            \Memcached::RES_FAILURE,
            \Memcached::RES_ERRNO,
            \Memcached::RES_CONNECTION_FAILURE,
            \Memcached::RES_READ_FAILURE,
            \Memcached::RES_UNKNOWN_READ_FAILURE,
        ], true);
    }

    /**
     * Same expiry moment for all keys of a metric
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
        self::log()->addError("{$message}{$suffix}", [__CLASS__]);
        $lastLogged = time();
        $suppressed = 0;
    }

    /**
     * Validated series name, null if it cannot be exposed
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
        self::logOnce("{$message}, metric skipped");
    }

    /**
     * Log a warning once per process
     */
    protected static function logOnce(string $message): void
    {
        static $logged = [];
        if (isset($logged[$message]) || count($logged) >= 1000) {
            return;
        }
        $logged[$message] = true;
        self::log()->addWarning($message, [__CLASS__]);
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

    /**
     * Save the series to the registry
     *
     * @return bool
     */
    protected static function saveName(string $key, string $name, string $type, array $extra = []): bool
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
        } catch (\Throwable $e) {
            self::logBackendError("register {$name}: {$e->getMessage()}");

            return false;
        }

        return true;
    }

    protected static function buildCacheKey(string $key, string $name): string
    {
        return self::KEY_PREFIX . "{$key}_" . md5($name);
    }

    public static function buildMetricsPage(): string
    {
        if (self::settings()['format'] === self::LEGACY_FORMAT_VERSION) {
            return self::buildLegacyMetricsPage();
        }
        $cache = self::cache();
        $response = '';
        $metrics = [];
        foreach (self::coll()->find(['version' => self::FORMAT_VERSION]) as $metric) {
            $metrics[$metric['cache_key']] = $metric;
        }

        $cached = [];
        $readError = false;
        $chunked = array_chunk(array_keys($metrics), 5000, true);
        foreach ($chunked as $chunk) {
            $memcached = $cache->getBackend();
            $cachedChunk = self::backendCall($memcached, true, 'getMulti', $chunk);
            if (!is_array($cachedChunk) || $memcached->getResultCode() === \Memcached::RES_SOME_ERRORS) {
                $readError = true;
                self::logBackendError("getMulti of " . count($chunk) . " keys: {$memcached->getResultMessage()}");
            }
            if (!is_array($cachedChunk)) {
                continue;
            }
            $cached = array_merge($cached, $cachedChunk);
        }
        unset($chunked, $chunk, $cachedChunk);

        // report a failed read in the page itself
        $errorLine = '# TYPE ' . self::SCRAPE_ERROR_METRIC . " gauge\n"
            . self::formatNameWithLabels(self::SCRAPE_ERROR_METRIC, []) . ' ' . ($readError ? 1 : 0) . "\n";

        if(empty($cached)) {

            return $errorLine;
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
                // _count expired: the whole series restarts
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

        return $response . $errorLine;
    }

    /**
     * Cumulative buckets, then _sum and _count
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

    /**
     * Expose a name used with several types as one untyped family
     */
    protected static function addFamilyLines(array &$families, string $family, string $type, array $lines): void
    {
        if (!isset($families[$family])) {
            $families[$family] = ['type' => $type, 'lines' => []];
        } elseif ($families[$family]['type'] !== $type && $families[$family]['type'] !== 'untyped') {
            self::logOnce("metric {$family} is registered as both {$families[$family]['type']} and {$type}, exposed as untyped");
            $families[$family]['type'] = 'untyped';
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
     * Sample value in the text exposition format
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
        // shortest round-trip representation
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

    /**
     * Page format and format 1 writes from the config
     *
     * @return array
     */
    protected static function settings(): array
    {
        static $settings;
        if ($settings === null) {
            $config = config::getConfig(__CLASS__);
            $format = (int)($config['format'] ?? self::DEFAULT_PAGE_FORMAT);
            if ($format !== self::LEGACY_FORMAT_VERSION && $format !== self::FORMAT_VERSION) {
                self::logOnce("unknown page format {$format}, format " . self::DEFAULT_PAGE_FORMAT . ' used');
                $format = self::DEFAULT_PAGE_FORMAT;
            }
            $settings = [
                'format' => $format,
                'write_legacy' => (bool)($config['write_legacy'] ?? self::DEFAULT_WRITE_LEGACY),
            ];
        }

        return $settings;
    }

    protected static function isLegacyWritten(): bool
    {
        return self::settings()['write_legacy'];
    }

    /**
     * Add to a format 1 counter like master: get + set
     *
     * @param int|float $counter
     */
    protected static function legacyIncrement(string $key, array $labels, $counter): void
    {
        if (!self::isLegacyWritten()) {
            return;
        }
        try {
            $name = self::formatLegacyName($key, $labels);
            $mcKey = "{$key}_" . md5($name);
            $cache = self::cache();
            $cv = $cache->get($mcKey);
            if ($cv === false) {
                // a read error must not reset the counter
                if ($cache->getBackend()->getResultCode() !== \Memcached::RES_NOTFOUND) {
                    self::logBackendError("legacy get {$mcKey}: {$cache->getBackend()->getResultMessage()}");

                    return;
                }
                if (!self::legacySaveName($key, $name, $mcKey, 'counter')) {
                    return;
                }
                $cv = $counter;
            } else {
                $cv += $counter;
            }
            self::legacySet($mcKey, $cv);
        } catch (\Throwable $e) {
            self::logBackendError("legacy write {$key}: {$e->getMessage()}");
        }
    }

    /**
     * @param mixed $value
     */
    protected static function legacySetGauge(string $key, array $labels, $value): void
    {
        if (!self::isLegacyWritten()) {
            return;
        }
        try {
            $name = self::formatLegacyName($key, $labels);
            $mcKey = "{$key}_" . md5($name);
            if (self::cache()->get($mcKey) === false && !self::legacySaveName($key, $name, $mcKey, 'gauge')) {
                return;
            }
            self::legacySet($mcKey, $value);
        } catch (\Throwable $e) {
            self::logBackendError("legacy write {$key}: {$e->getMessage()}");
        }
    }

    /**
     * Non-cumulative format 1 buckets, strict le and int value like master
     *
     * @param int|float $value
     */
    protected static function legacyHistogram(string $name, array $labels, array $buckets, $value): void
    {
        if (!self::isLegacyWritten()) {
            return;
        }
        $value = (int)$value;
        $activeBucket = '+Inf';
        foreach ($buckets as $bucket) {
            if ($value < $bucket) {
                $activeBucket = $bucket;
                break;
            }
        }
        $bucketLabels = $labels;
        $bucketLabels['le'] = $activeBucket;
        self::legacyIncrement("{$name}_bucket", $bucketLabels, 1);
        self::legacyIncrement("{$name}_count", $labels, 1);
        self::legacyIncrement("{$name}_sum", $labels, $value);
    }

    /**
     * @param mixed $value
     */
    protected static function legacySet(string $mcKey, $value): void
    {
        $cache = self::cache();
        if (!$cache->set($mcKey, $value, self::LEGACY_EXPIRE)) {
            self::logBackendError("legacy set {$mcKey}: {$cache->getBackend()->getResultMessage()}");
        }
    }

    /**
     * Save a series to the format 1 registry, false to skip the write
     */
    protected static function legacySaveName(string $key, string $name, string $mcKey, string $type): bool
    {
        if ((self::$legacyRetryAt[$mcKey] ?? 0) > time()) {
            return false;
        }
        try {
            $newObj = [
                'key' => $key,
                'name' => $name,
                'type' => $type,
                'cache_key' => $mcKey,
            ];
            self::legacyColl()->insert($newObj);
        } catch (\MongoDuplicateKeyException $e) {
            //pass
        } catch (\Throwable $e) {
            self::logBackendError("legacy register {$name}: {$e->getMessage()}");
            // the key stays absent, so the next write registers it
            self::$legacyRetryAt[$mcKey] = time() + self::REGISTRATION_RETRY_INTERVAL;

            return false;
        }
        unset(self::$legacyRetryAt[$mcKey]);

        return true;
    }

    /**
     * Series name of format 1: env first, labels in call order, not escaped
     */
    protected static function formatLegacyName(string $key, array $labels): string
    {
        static $systemData;
        if ($systemData === null) {
            $systemData = [
                'env' => environment::getCurrentEnvironment(),
            ];
        }

        $labelData = [];
        foreach ($systemData as $label => $labelValue) {
            $labelData[$label] = "{$label}=\"{$labelValue}\"";
        }
        foreach ($labels as $label => $labelValue) {
            $labelData[$label] = "{$label}=\"{$labelValue}\"";
        }
        $labelsStr = implode(', ', $labelData);

        return "{$key}{{$labelsStr}}";
    }

    /**
     * Page of format 1, the same as master builds
     */
    protected static function buildLegacyMetricsPage(): string
    {
        if (!self::isLegacyWritten()) {
            self::logOnce('page format 1 with write_legacy off shows only series of older versions');
        }
        $cache = self::cache();
        $response = '';
        $metrics = [];
        foreach (self::legacyColl()->find() as $metric) {
            $metrics[$metric['cache_key']] = $metric;
        }

        $cached = [];
        $chunked = array_chunk(array_keys($metrics), 5000, true);
        foreach ($chunked as $chunk) {
            $memcached = $cache->getBackend();
            $cachedChunk = self::backendCall($memcached, true, 'getMulti', $chunk);
            if (!is_array($cachedChunk)) {
                self::logBackendError("legacy getMulti of " . count($chunk) . " keys: {$memcached->getResultMessage()}");
                continue;
            }
            $cached = array_merge($cached, $cachedChunk);
        }
        unset($chunked, $chunk, $cachedChunk);

        if (empty($cached)) {

            return $response;
        }

        $metricGrouped = [];
        foreach ($cached as $key => $value) {
            $value = (float)($value);
            $groupKey = "#TYPE {$metrics[$key]['key']} {$metrics[$key]['type']}";
            $metricGrouped[$groupKey][] = "{$metrics[$key]['name']} {$value}";
        }

        foreach ($metricGrouped as $groupKey => $groupMetrics) {
            $response .= $groupKey . "\n" . implode("\n", $groupMetrics) . "\n";
        }

        return $response;
    }

    protected static function legacyColl(): \MongoCollection
    {
        static $mongo, $config;
        if ($mongo === null) {
            $config = config::getConfig(__CLASS__)['storage'];
            $mongo = mongoInstance::factory($config['configSection']);
            $mongo->checkIndicesAuto($config);
        }

        return $mongo->getCollection($config['db'], $config['collection']);
    }

    protected static function coll(): \MongoCollection
    {
        static $mongo, $config;
        if ($mongo === null) {
            $config = config::getConfig(__CLASS__)['storage'];
            $config['collection'] .= self::REGISTRY_SUFFIX;
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