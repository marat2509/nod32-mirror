<?php

declare(strict_types=1);

define('DS', DIRECTORY_SEPARATOR);
define('SELF', dirname(__DIR__) . DS);
define('PATTERN', SELF . 'patterns' . DS);
define('CONF_FILE', SELF . 'nod32-mirror.yaml');
define('LANGPACKS_DIR', SELF . 'langpacks' . DS);

/**
 * Application version (YYYYMMDD format)
 */
define('APP_VERSION', '20260121');
