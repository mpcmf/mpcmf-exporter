<?php

\mpcmf\system\configuration\config::setConfig(__FILE__, [
    // page format: 1 = master keys and registry, 2 = v2
    'format' => 1,
    // also write the format 1 keys and registry
    'write_legacy' => true,
    'storage' => [
        'configSection' => 'default',
        'db' => 'prometheus',
        'collection' => 'metrics',
        'indices' => [
            [
                'keys' => [
                    'cache_key' => 1,
                ],
                'options' => [
                    'unique' => true
                ]
            ]
        ],
    ],
    'cache' => [
        'configSection' => 'default'
    ]
]);

