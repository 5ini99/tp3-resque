<?php
spl_autoload_register(function ($class) {
    $path = str_replace('\\', DIRECTORY_SEPARATOR, $class);
    $file = __DIR__ . '/src/' . $path . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
require_once __DIR__ . '/src/functions.php';
