<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Run work deferred until after the response, such as document imports,
     * straight away. Server-side rendering is off: with the Vite dev server
     * running, Inertia would render every page through it, so tests would
     * depend on whether a dev server happens to be up.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer();

        config(['inertia.ssr.enabled' => false]);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
