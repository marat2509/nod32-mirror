<?php

declare(strict_types=1);

define('DS', DIRECTORY_SEPARATOR);
define('SELF', dirname(__DIR__) . DS);
define('PATTERN', SELF . 'patterns' . DS);
define('CONF_FILE', SELF . 'nod32-mirror.yaml');
define('LANGPACKS_DIR', SELF . 'langpacks' . DS);
define('DEBUG_DIR', 'debug');
define('TMP_PATH', SELF . 'tmp' . DS);
define('KEY_FILE', 'keys.json');
define('LOG_FILE', 'nod32ms.log');
define('SUCCESSFUL_TIMESTAMP', 'lastupdate.json');
define('DATABASES_SIZE', 'databases_size.json');
