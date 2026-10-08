<?php
declare(strict_types=1);
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'Talixio\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($file)) require $file;
    }
});
