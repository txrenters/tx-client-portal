<?php

use App\Enums\ClientRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\Demo\DemoData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // No test may reach a real service. A request no Http::fake() matches
    // otherwise goes out for real, which once sent a test to the live
    // Propertyware API with a developer's credentials from .env.
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Feature');

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
 * Request a page's deferred props the way the browser asks for them.
 */
function partialGet(string $uri, string $component, string ...$props): TestResponse
{
    return test()->get($uri, [
        'X-Inertia' => 'true',
        // A mismatched version would answer 409 instead of the props under test.
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => implode(',', $props),
    ]);
}

/**
 * One of the App Review logins, linked to its made-up Propertyware contact.
 *
 * @return array{0: User, 1: ClientAccount}
 */
function demoLogin(ClientRole $role): array
{
    $user = User::factory()->demo()->create([
        'email' => $role === ClientRole::Owner ? 'appreview.owner@texasrenters.com' : 'appreview.tenant@texasrenters.com',
    ]);
    $account = ClientAccount::factory()->for($user)->create([
        'role' => $role,
        'propertyware_id' => (string) ($role === ClientRole::Owner ? DemoData::OWNER_CONTACT : DemoData::TENANT_CONTACT),
    ]);

    return [$user, $account];
}
