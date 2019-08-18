<?php
// Set up autoloader for lib folder (Copied from /bootstrap.php)
spl_autoload_register(function ($class) {
    $class_path = __DIR__ . '/../lib/' . str_replace('\\', '/', $class) . '.php';

    if (file_exists ($class_path)) include_once($class_path);
});
