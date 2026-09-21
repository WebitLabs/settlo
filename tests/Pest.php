<?php

use App\Models\BusinessEntity;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TaxProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Vite;
use Pest\Browser\Api\Webpage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Unit');

/*
 * Real-browser tests. Pest serves the application in-process, so factories,
 * `actingAs()` and the refreshed database are shared with the page under test.
 *
 * The compiled manifest (`npm run build`) is always used, never a running Vite
 * dev server: its cross-origin rules would drop the stylesheets and leave every
 * layout assertion looking at unstyled HTML.
 */
pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->beforeEach(function (): void {
        Vite::useHotFile(storage_path('framework/testing/vite-dev-server-disabled'));
    })
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A verified owner with a personal tax profile, a sole-proprietorship
 * workspace in the given canton and a subscription on the given plan (when the
 * plans are seeded). Requires the cantons to be seeded.
 *
 * @return array{0: User, 1: BusinessEntity}
 */
function workspaceOwner(string $planCode = 'pro', string $cantonCode = 'ZH'): array
{
    $user = User::factory()->owner()->create();
    TaxProfile::factory()->forCanton($cantonCode)->for($user)->create();
    $entity = BusinessEntity::factory()->forCanton($cantonCode)->for($user, 'owner')->create();

    $plan = Plan::where('code', $planCode)->first();
    Subscription::factory()->forEntity($entity)->create($plan ? ['plan_id' => $plan->getKey()] : []);

    return [$user, $entity];
}

/**
 * Sign in and make $entity the current workspace tenant.
 */
function actAsWorkspace(User $user, BusinessEntity $entity): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('workspace'));
    Filament::setTenant($entity);
}

/**
 * The workspace panel URL of the given business, e.g.
 * workspaceUrl($entity, 'invoices/create').
 */
function workspaceUrl(BusinessEntity $entity, string $path = ''): string
{
    return rtrim('/app/w/'.$entity->getKey().'/'.ltrim($path, '/'), '/');
}

/**
 * Poll the page until the JavaScript expression evaluates to true. Livewire and
 * Alpine update the DOM after a round trip, which the plugin's assertions do
 * not wait for on their own.
 *
 * @throws RuntimeException when the expression is still false after the timeout
 */
function waitForScript(Webpage $page, string $expression, float $timeoutSeconds = 8.0): void
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (true) {
        if ($page->script($expression) === true) {
            return;
        }

        if (microtime(true) >= $deadline) {
            throw new RuntimeException("Timed out after {$timeoutSeconds}s waiting for [{$expression}].");
        }

        $page->wait(0.15);
    }
}

/**
 * Wait until the given text is rendered somewhere in the page body.
 */
function waitForBodyText(Webpage $page, string $text, float $timeoutSeconds = 8.0): void
{
    waitForScript($page, 'document.body.innerText.includes('.json_encode($text).')', $timeoutSeconds);
}

/**
 * Wait until the given text has disappeared from the page body.
 */
function waitForBodyTextToGo(Webpage $page, string $text, float $timeoutSeconds = 8.0): void
{
    waitForScript($page, '! document.body.innerText.includes('.json_encode($text).')', $timeoutSeconds);
}

/**
 * Whether the element matching the selector overflows its own box horizontally
 * (a row wider than the card it sits in), with a pixel of tolerance for
 * sub-pixel rounding.
 */
function overflowsHorizontally(Webpage $page, string $selector): bool
{
    return $page->script(sprintf(
        '(() => { const el = document.querySelector(%s); if (! el) { return null; } return el.scrollWidth - el.clientWidth > 1; })()',
        json_encode($selector),
    )) === true;
}
