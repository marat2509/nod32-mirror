<?php

require __DIR__ . "/inc/init.php";

try {
    Log::init();
    Language::init();
    Config::init();
    Language::init();

    @ini_set('memory_limit', Config::get('SCRIPT')['memory_limit']);

    $nod32ms = new Nod32ms();
}
catch (ToolsException $e) {
    Log::write_log($e->getMessage(), 0);
}
catch (ConfigException $e) {
    Log::write_log($e->getMessage(), 0);
}
catch (\PHPMailer\PHPMailer\Exception $e) {
    Log::write_log($e->getMessage(), 0);
}
catch (Exception $e) {
    Log::write_log($e->getMessage(), 0);
}
