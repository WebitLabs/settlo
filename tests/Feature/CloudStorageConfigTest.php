<?php

/**
 * The receipts and public disks default to local storage (unchanged local dev
 * behavior) and switch to DigitalOcean Spaces (s3 driver) purely via env vars.
 * The s3 branches are asserted by re-evaluating the config file with the
 * driver env vars set, since env() is only read at config load time.
 *
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function loadFilesystemsConfigWithEnv(array $env): array
{
    foreach ($env as $key => $value) {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    try {
        return require config_path('filesystems.php');
    } finally {
        foreach (array_keys($env) as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }
}

it('keeps the receipts disk on local storage by default', function () {
    $receipts = config('filesystems.disks.receipts');

    expect($receipts['driver'])->toBe('local')
        ->and($receipts['root'])->toBe(storage_path('app/receipts'))
        ->and($receipts['visibility'])->toBe('private')
        ->and($receipts['serve'])->toBeFalse();
});

it('keeps the public disk on local storage by default', function () {
    $public = config('filesystems.disks.public');

    expect($public['driver'])->toBe('local')
        ->and($public['root'])->toBe(storage_path('app/public'))
        ->and($public['visibility'])->toBe('public');
});

it('switches the receipts disk to a private spaces bucket when RECEIPTS_DISK_DRIVER=s3', function () {
    $config = loadFilesystemsConfigWithEnv([
        'RECEIPTS_DISK_DRIVER' => 's3',
        'DO_SPACES_KEY' => 'spaces-key',
        'DO_SPACES_SECRET' => 'spaces-secret',
        'DO_SPACES_REGION' => 'fra1',
        'DO_SPACES_BUCKET' => 'settlo-prod',
        'DO_SPACES_ENDPOINT' => 'https://fra1.digitaloceanspaces.com',
    ]);

    $receipts = $config['disks']['receipts'];

    expect($receipts['driver'])->toBe('s3')
        ->and($receipts['key'])->toBe('spaces-key')
        ->and($receipts['secret'])->toBe('spaces-secret')
        ->and($receipts['region'])->toBe('fra1')
        ->and($receipts['bucket'])->toBe('settlo-prod')
        ->and($receipts['endpoint'])->toBe('https://fra1.digitaloceanspaces.com')
        ->and($receipts['root'])->toBe('receipts')
        ->and($receipts['visibility'])->toBe('private')
        ->and($receipts['use_path_style_endpoint'])->toBeFalse();
});

it('switches the public disk to a cdn-fronted spaces bucket when PUBLIC_DISK_DRIVER=s3', function () {
    $config = loadFilesystemsConfigWithEnv([
        'PUBLIC_DISK_DRIVER' => 's3',
        'DO_SPACES_KEY' => 'spaces-key',
        'DO_SPACES_SECRET' => 'spaces-secret',
        'DO_SPACES_REGION' => 'fra1',
        'DO_SPACES_BUCKET' => 'settlo-prod',
        'DO_SPACES_ENDPOINT' => 'https://fra1.digitaloceanspaces.com',
        'DO_SPACES_CDN_URL' => 'https://settlo-prod.fra1.cdn.digitaloceanspaces.com/public',
    ]);

    $public = $config['disks']['public'];

    expect($public['driver'])->toBe('s3')
        ->and($public['root'])->toBe('public')
        ->and($public['url'])->toBe('https://settlo-prod.fra1.cdn.digitaloceanspaces.com/public')
        ->and($public['visibility'])->toBe('public')
        ->and($public['use_path_style_endpoint'])->toBeFalse();
});

it('leaves the livewire temporary upload disk on the app default unless overridden', function () {
    expect(config('livewire.temporary_file_upload.disk'))->toBeNull();
});
