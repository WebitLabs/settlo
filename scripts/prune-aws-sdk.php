<?php

declare(strict_types=1);

/**
 * Drop the AWS service definitions the app never loads.
 *
 * aws/aws-sdk-php ships an API model for all ~430 AWS services — 47 MB of the
 * installed package — while Settlo uses exactly one: S3, for receipt and logo
 * storage on DigitalOcean Spaces. On Vercel the whole vendor directory is
 * bundled into the function, and the bundle has a hard 250 MB limit that the
 * unpruned SDK pushes it over.
 *
 * Invoked only by the `vercel` composer script, which the vercel-php runtime
 * runs during its build phase — its own `composer install` runs with
 * --no-scripts, so the usual hooks never fire. Local and CI installs keep the
 * full SDK, and a plain `composer install` restores everything.
 */
$data = __DIR__.'/../vendor/aws/aws-sdk-php/src/data';

if (! is_dir($data)) {
    exit(0);
}

/**
 * Service directories to keep. `s3` is the one in use; `sts` backs temporary
 * credentials, which the credential provider chain can reach for even when the
 * app itself only talks to S3.
 */
$keep = ['s3', 'sts'];

$removedBytes = 0;

foreach (new DirectoryIterator($data) as $entry) {
    if ($entry->isDot() || ! $entry->isDir() || in_array($entry->getFilename(), $keep, true)) {
        continue;
    }

    $path = $entry->getPathname();
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());

            continue;
        }

        $removedBytes += $file->getSize();
        unlink($file->getPathname());
    }

    rmdir($path);
}

printf('Pruned unused AWS service models: %.1f MB freed.%s', $removedBytes / 1024 / 1024, PHP_EOL);
