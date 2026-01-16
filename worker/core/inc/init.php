<?php

chdir(__DIR__ . "/..");


$VERSION = '20250919';

@define('DS', DIRECTORY_SEPARATOR);
@define('SELF', dirname(__DIR__) . DS);
@define('INC', SELF . "inc" . DS);
@define('CLASSES', INC . "classes" . DS);
@define('PATTERN', SELF . "patterns" . DS);
@define('CONF_FILE', SELF . "nod32ms.conf");
@define('LANGPACKS_DIR', SELF . 'langpacks' . DS);
@define('DEBUG_DIR', SELF . 'debug' . DS);
@define('TMP_PATH', SELF . 'tmp' . DS);
@define('KEY_FILE_VALID', 'nod_keys.valid');
@define('KEY_FILE_INVALID', 'nod_keys.invalid');
@define('LOG_FILE', 'nod32ms.log');
@define('SUCCESSFUL_TIMESTAMP', 'nod_lastupdate');
@define('DATABASES_SIZE', 'nod_databases_size');


$autoload = function ($class) {
    @include_once CLASSES . "$class.class.php";
};
spl_autoload_register($autoload);

$DIRECTORIES = [
    'ep6' => [
        'name' => 'ESET NOD32 Endpoint 6',
        'channels' => [
            'production' => [
                'file' => 'eset_upd/ep6.6/update.ver',
                'dll' => 'eset_upd/ep6.6/dll/update.ver'
            ],
            'deferred' => [
                'file' => 'deferred/eset_upd/ep6.6/update.ver',
                'dll' => 'deferred/eset_upd/ep6.6/dll/update.ver'
            ]
        ]
    ],
    'ep8' => [
        'name' => 'ESET NOD32 Endpoint 7-8',
        'channels' => [
            'production' => [
                'file' => 'eset_upd/ep8/update.ver',
                'dll' => 'eset_upd/ep8/dll/update.ver'
            ],
            'deferred' => [
                'file' => 'deferred/eset_upd/ep8/update.ver',
                'dll' => 'deferred/eset_upd/ep8/dll/update.ver'
            ]
        ]
    ],
    'ep9' => [
        'name' => 'ESET NOD32 Endpoint 9',
        'channels' => [
            'production' => [
                'file' => 'eset_upd/ep9/update.ver',
                'dll' => 'eset_upd/ep9/dll/update.ver'
            ],
            'deferred' => [
                'file' => 'deferred/eset_upd/ep9/update.ver',
                'dll' => 'deferred/eset_upd/ep9/dll/update.ver'
            ],
            'pre-release' => [
                'file' => 'eset_upd/ep9/pre/update.ver',
                'dll' => 'eset_upd/ep9/pre/dll/update.ver'
            ]
        ]
    ],
    'ep10' => [
        'name' => 'ESET NOD32 Endpoint 10-11',
        'channels' => [
            'production' => [
                'file' => false,
                'dll' => 'eset_upd/ep10/dll/update.ver'
            ],
            'deferred' => [
                'file' => false,
                'dll' => 'deferred/eset_upd/ep10/dll/update.ver'
            ],
            'pre-release' => [
                'file' => false,
                'dll' => 'eset_upd/ep10/pre/dll/update.ver'
            ]
        ]
    ],
    'ep12' => [
        'name' => 'ESET NOD32 Endpoint 12',
        'channels' => [
            'production' => [
                'file' => false,
                'dll' => 'eset_upd/ep12/dll/update.ver'
            ],
            'deferred' => [
                'file' => false,
                'dll' => 'deferred/eset_upd/ep12/dll/update.ver'
            ],
            'pre-release' => [
                'file' => false,
                'dll' => 'eset_upd/ep12/pre/dll/update.ver'
            ]
        ]
    ],
    'v3' => [
        'name' => 'ESET NOD32 3-9',
        'channels' => [
            'production' => [
                'file' => 'eset_upd/update.ver',
                'dll' => false
            ],
            'deferred' => [
                'file' => false,
                'dll' => 'deferred/eset_upd/update.ver'
            ]
        ]
    ],
    'v10' => [
        'name' => 'ESET NOD32 10-13',
        'channels' => [
            'production' => [
                'file' => 'eset_upd/v10/update.ver',
                'dll' => false
            ],
            'pre-release' => [
                'file' => 'eset_upd/v10/pre/update.ver',
                'dll' => false
            ]
        ]
    ],
    'v16' => [
        'name' => 'ESET NOD32 14-19',
        'channels' => [
            'production' => [
                'file' => false,
                'dll' => 'eset_upd/v16/dll/update.ver'
            ],
            'pre-release' => [
                'file' => false,
                'dll' => 'eset_upd/v16/pre/dll/update.ver'
            ]
        ]
    ]
];
