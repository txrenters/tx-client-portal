<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\IntegrationHealth;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationController extends Controller
{
    /**
     * Every service the portal depends on, with its current state.
     */
    public function index(IntegrationHealth $health): Response
    {
        return Inertia::render('admin/Integrations', [
            'queue' => $health->queue(),
            'integrations' => Inertia::defer(fn (): array => $health->all()),
        ]);
    }

    /**
     * Check the services again now, rather than waiting for the cache to lapse.
     */
    public function refresh(IntegrationHealth $health): RedirectResponse
    {
        $health->all(fresh: true);

        return back()->with('success', 'Integrations checked again.');
    }
}
