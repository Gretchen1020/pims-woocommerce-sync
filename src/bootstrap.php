<?php
declare(strict_types=1);

// Minimal PSR-4 style autoloader: Pims\Foo\Bar -> src/Foo/Bar.php
spl_autoload_register(function (string $class): void {
    $prefix = 'Pims\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});
