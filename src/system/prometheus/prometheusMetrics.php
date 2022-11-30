<?php

namespace mpcmf\system\prometheus;

use mpcmf\system\configuration\config;
use mpcmf\system\configuration\environment;
use mpcmf\system\helper\cache\cache;
use mpcmf\system\helper\io\log;
use mpcmf\system\storage\mongoInstance;

class prometheusMetrics
{

    protected const CACHE_EXPIRE = 86400;

    use log, cache;

    public static function incrementCounter(string $key, array $labels = [], $counter = 1): void
    {
        $name = self::formatNameWithLabels($key, $labels);

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

    public static function setGauge(string $key, array $labels = [], $value = 0): void
    {
        $name = self::formatNameWithLabels($key, $labels);

        $mcKey = self::buildCacheKey($key, $name);
        $cache = self::cache();
        if($cache->get($mcKey) === false) {
            self::saveName($key, $name, 'gauge');
        }
        $cache->set($mcKey, $value, self::CACHE_EXPIRE);
    }

    protected static function formatNameWithLabels(string $key, array $labels): string
    {
        static $systemData;
        if($systemData === null) {
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
        $metricGrouped = [];
        foreach (self::coll()->find() as $metric) {
            $cached = $cache->get($metric['cache_key']);
            if($cached === false) {

                continue;
            }
            $value = (float)($cached);
            $groupKey = "#TYPE {$metric['key']} {$metric['type']}";
            $metricGrouped[$groupKey][] = "{$metric['name']} {$value}";
        }

        foreach ($metricGrouped as $groupKey => $metrics) {
            $response .= $groupKey . "\n" . implode("\n", $metrics) . "\n";
        }

        return $response;
    }

    protected static function coll() : \MongoCollection
    {
        static $mongo, $config;
        if ($mongo === null) {
            $mongo = mongoInstance::factory();
            $config = config::getConfig(__CLASS__);
            $mongo->checkIndicesAuto($config);
        }

        return $mongo->getCollection($config['db'], $config['collection']);
    }
}