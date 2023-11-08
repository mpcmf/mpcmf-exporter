<?php

\mpcmf\system\configuration\config::setConfig(__FILE__, [
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

