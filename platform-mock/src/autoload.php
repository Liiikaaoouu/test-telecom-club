<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'PlatformMock\\')) {
        $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen('PlatformMock\\'))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});
