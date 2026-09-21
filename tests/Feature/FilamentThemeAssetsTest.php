<?php

use Symfony\Component\Finder\Finder;

/**
 * resources/css/filament/theme.css imports Filament's panel theme straight out
 * of vendor/, and that theme imports the CSS of every sibling Filament package.
 * Vercel's build image has no PHP, so `composer install` cannot run before
 * `npm run build` there — a build from git can only resolve those imports if
 * the packages' CSS is tracked in the repository (see .gitignore).
 *
 * Tracked vendor files drift the moment Filament is upgraded, and the symptom
 * would be a deploy failing in CI minutes later rather than a red test here.
 * These tests fail instead, and the fix is to re-add the directories:
 *
 *     git add -f vendor/filament/*&#47;resources/css
 */
/** @var list<string> the package directories whose CSS the Vite build reads */
const FILAMENT_CSS_GLOBS = ['vendor/filament/*/resources/css', 'vendor/filament/*/dist'];

/**
 * Every installed Filament package CSS file, relative to the project root.
 *
 * @return list<string>
 */
function filamentPackageCssFiles(): array
{
    $files = [];

    foreach (FILAMENT_CSS_GLOBS as $pattern) {
        foreach (glob(base_path($pattern), GLOB_ONLYDIR) ?: [] as $directory) {
            foreach (Finder::create()->files()->in($directory)->name('*.css') as $file) {
                $files[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    sort($files);

    return $files;
}

it('keeps the theme entrypoint importing the packaged Filament CSS', function () {
    $theme = (string) file_get_contents(resource_path('css/filament/theme.css'));

    expect($theme)->toContain('vendor/filament/filament/resources/css/theme.css')
        ->and(file_exists(base_path('vendor/filament/filament/resources/css/theme.css')))->toBeTrue();
});

it('tracks every packaged CSS file the build needs in git', function () {
    $tracked = [];
    exec('git ls-files -- vendor/filament', $tracked);

    // git pathspecs do not glob across directory separators here, so the
    // package directory is listed whole and filtered in PHP.
    $trackedCss = array_values(array_filter(
        $tracked,
        static fn (string $path): bool => str_ends_with($path, '.css')
    ));

    sort($trackedCss);

    // An upgrade that adds, removes or renames a file lands here, as does a new
    // Filament package whose CSS nothing has tracked yet.
    expect($trackedCss)->toBe(filamentPackageCssFiles())
        ->and($trackedCss)->not->toBeEmpty();
});

it('keeps the tracked CSS identical to the installed packages', function () {
    $changed = [];
    exec('git diff --name-only -- vendor/filament', $changed);

    // An upgrade rewrites these files in place; git would then hold a stale
    // copy, and a build from git would compile that instead of what ships.
    expect($changed)->toBe([]);
});
