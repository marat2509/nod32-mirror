<?php

require __DIR__ . "/inc/init.php";

try {
    Config::init();
    Log::init();
    Language::init();

    @ini_set('memory_limit', Config::get('script')['memory_limit']);

    $nod32ms = new Nod32ms();
}
catch (ToolsException $e) {
    Log::isInitialized() ? Log::error($e->getMessage()) : error_log($e->getMessage());
}
catch (ConfigException $e) {
    Log::isInitialized() ? Log::error($e->getMessage()) : error_log($e->getMessage());
}
catch (Exception $e) {
    Log::isInitialized() ? Log::error($e->getMessage()) : error_log($e->getMessage());
}
