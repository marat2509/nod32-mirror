<?php

declare(strict_types=1);

/**
 * Version directory configuration
 *
 * Each version can have multiple channels (production, deferred, pre-release)
 * Each channel can have file-based and/or dll-based updates
 */

$DIRECTORIES = [
    'ep6' => [
        'name' => 'ESET NOD32 Endpoint 6',
        'channels' => [
            'production' => [
                'file' => 'eset_upd/ep6.6/update.ver',
                'dll' => 'eset_upd/ep6.6/dll/update.ver',
            ],
            'deferred' => [
                'file' => 'deferred/eset_upd/ep6.6/update.ver',
                'dll' => 'deferred/eset_upd/ep6.6/dll/update.ver',
            ],
        ],
    ],
    'ep8' => [
        'name' => 'ESET NOD32 Endpoint 7-8',
        'channels' => [
            'production' => [
                'file' => 'eset_upd/ep8/update.ver',
                'dll' => 'eset_upd/ep8/dll/update.ver',
            ],
            'deferred' => [
                'file' => 'deferred/eset_upd/ep8/update.ver',
                'dll' => 'deferred/eset_upd/ep8/dll/update.ver',
            ],
        ],
    ],
    'ep9' => [
        'name' => 'ESET NOD32 Endpoint 9',
        'channels' => [
            'production' => [
                'file' => 'eset_upd/ep9/update.ver',
                'dll' => 'eset_upd/ep9/dll/update.ver',
            ],
            'deferred' => [
                'file' => 'deferred/eset_upd/ep9/update.ver',
                'dll' => 'deferred/eset_upd/ep9/dll/update.ver',
            ],
            'pre-release' => [
                'file' => 'eset_upd/ep9/pre/update.ver',
                'dll' => 'eset_upd/ep9/pre/dll/update.ver',
            ],
        ],
    ],
    'ep10' => [
        'name' => 'ESET NOD32 Endpoint 10-11',
        'channels' => [
            'production' => [
                'file' => false,
                'dll' => 'eset_upd/ep10/dll/update.ver',
            ],
            'deferred' => [
                'file' => false,
                'dll' => 'deferred/eset_upd/ep10/dll/update.ver',
            ],
            'pre-release' => [
                'file' => false,
                'dll' => 'eset_upd/ep10/pre/dll/update.ver',
            ],
        ],
    ],
    'ep12' => [
        'name' => 'ESET NOD32 Endpoint 12',
        'channels' => [
            'production' => [
                'file' => false,
                'dll' => 'eset_upd/ep12/dll/update.ver',
            ],
            'deferred' => [
                'file' => false,
                'dll' => 'deferred/eset_upd/ep12/dll/update.ver',
            ],
            'pre-release' => [
                'file' => false,
                'dll' => 'eset_upd/ep12/pre/dll/update.ver',
            ],
        ],
    ],
    'v3' => [
        'name' => 'ESET NOD32 3-9',
        'channels' => [
            'production' => [
                'file' => 'eset_upd/update.ver',
                'dll' => false,
            ],
            'deferred' => [
                'file' => 'deferred/eset_upd/update.ver',
                'dll' => false,
            ],
        ],
    ],
    'v10' => [
        'name' => 'ESET NOD32 10-13',
        'channels' => [
            'production' => [
                'file' => 'eset_upd/v10/update.ver',
                'dll' => false,
            ],
            'pre-release' => [
                'file' => 'eset_upd/v10/pre/update.ver',
                'dll' => false,
            ],
        ],
    ],
    'v16' => [
        'name' => 'ESET NOD32 14-19',
        'channels' => [
            'production' => [
                'file' => false,
                'dll' => 'eset_upd/v16/dll/update.ver',
            ],
            'pre-release' => [
                'file' => false,
                'dll' => 'eset_upd/v16/pre/dll/update.ver',
            ],
        ],
    ],
];
