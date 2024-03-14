<?php

chdir(__DIR__ . "/..");


$VERSION = '20240308';

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
    'ep6.6' => [
        'file' => 'eset_upd/ep6.6/update.ver',
        'dll' => 'eset_upd/ep6.6/dll/update.ver',
        'name' => 'ESET NOD32 Endpoint 6'
    ],
    'ep7' => [
        'file' => 'eset_upd/ep7/update.ver',
        'dll' => 'eset_upd/ep7/dll/update.ver',
        'name' => 'ESET NOD32 Endpoint 7'
    ],
    'ep8' => [
        'file' => 'eset_upd/ep8/update.ver',
        'dll' => 'eset_upd/ep8/dll/update.ver',
        'name' => 'ESET NOD32 Endpoint 8'
    ],
    'ep9' => [
        'file' => 'eset_upd/ep9/update.ver',
        'dll' => 'eset_upd/ep9/dll/update.ver',
        'name' => 'ESET NOD32 Endpoint 9'
    ],
    'ep10' => [
        'file' => 'eset_upd/ep10/update.ver',
        'dll' => 'eset_upd/ep10/dll/update.ver',
        'name' => 'ESET NOD32 Endpoint 10'
    ],
    'ep11' => [
        'file' => 'eset_upd/ep11/update.ver',
        'dll' => 'eset_upd/ep11/dll/update.ver',
        'name' => 'ESET NOD32 Endpoint 11'
    ],
    'v3' => [
        'file' => 'eset_upd/update.ver',
        'dll' => false,
        'name' => 'ESET NOD32 3-8'
    ],
    'v10' => [
        'file' => 'eset_upd/v10/update.ver',
        'dll' => false,
        'name' => 'ESET NOD32 10-13'
    ],
    'v14' => [
        'file' => 'eset_upd/v14/update.ver',
        'dll' => 'eset_upd/v14/dll/update.ver',
        'name' => 'ESET NOD32 14'
    ],
    'v15' => [
        'file' => 'eset_upd/v15/update.ver',
        'dll' => 'eset_upd/v15/dll/update.ver',
        'name' => 'ESET NOD32 15'
    ],
    'v16' => [
        'file' => 'eset_upd/v16/update.ver',
        'dll' => 'eset_upd/v16/dll/update.ver',
        'name' => 'ESET NOD32 16-17'
    ]
];

