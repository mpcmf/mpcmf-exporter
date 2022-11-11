<?php

namespace mpcmf\system\prometheus;

use mpcmf\system\configuration\config;
use mpcmf\system\configuration\environment;
use mpcmf\system\helper\cache\cache;
use mpcmf\system\helper\io\log;
use mpcmf\system\storage\mongoInstance;

class prometheusMetrics
{

    use log, cache;
    
    public static function incrementCounter(string $key, array $labels = [], $counter = 1) 
    {
        $name = self::formatNameWithLabels($key, $labels);
        
        $cv = self::cache()->inc(md5($name), $counter, 0, 86400);
        if($cv % 100 === 0) {
            self::saveName($key, $name, 'counter');
        }
    }
    
    public static function setGauge(string $key, array $labels = [], $value = 0) 
    {
        $name = self::formatNameWithLabels($key, $labels);
        
        $mcKey = md5($name);
        $bk = self::cache()->getBackend();
        if($bk->get($mcKey) === false) {
            self::saveName($key, $name, 'gauge');
        }
        $cv = $bk->set($mcKey, $value, 0, 86400);
    }
    
    protected static function formatNameWithLabels(string $key, array $labels) :string
    {
        static $systemData;
        if($systemData === null) {
            $systemData = [
                'env' => environment::getCurrentEnvironment(),
                'hostname' => gethostname(), 
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
                'cache_key' => md5($name)
            ];
            self::coll()->insert($newObj);
        } catch (\MongoDuplicateKeyException $e) {
            //pass
        }
    }
    
    public static function buildMetricsPage() :string
    {
        $cache = self::cache();
        $response = '';
        foreach (self::coll()->find() as $metric) {
            $cached = $cache->get($metric['cache_key']);
            if($cached === false) {
                
                continue;
            }
            $value = (float)($cached);
            $str = "# TYPE {$metric['key']} {$metric['type']}\n";
            $str .= "{$metric['name']} {$value}\n";

            $response .= $str;
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