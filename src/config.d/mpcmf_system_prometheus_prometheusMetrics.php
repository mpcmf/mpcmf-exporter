<?php

\mpcmf\system\configuration\config::setConfig(__FILE__, [
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
]);

