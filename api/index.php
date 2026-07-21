<?php

/**
 * Vercel serverless entry point.
 *
 * The lambda filesystem is read-only except /tmp, so compiled Blade views are
 * redirected there unless VIEW_COMPILED_PATH is provided by the environment.
 */
$writablePaths = [
    'VIEW_COMPILED_PATH' => '/tmp/views',
    'APP_PACKAGES_CACHE' => '/tmp/bootstrap-cache/packages.php',
    'APP_SERVICES_CACHE' => '/tmp/bootstrap-cache/services.php',
];

foreach ($writablePaths as $name => $default) {
    $value = getenv($name);

    if ($value === false || $value === '') {
        $value = $default;

        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    $directory = str_ends_with($value, '.php') ? dirname($value) : $value;

    if (! is_dir($directory)) {
        @mkdir($directory, 0755, true);
    }
}

require __DIR__.'/../public/index.php';
