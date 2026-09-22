<?php

namespace App\Http\Controllers\Owner;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ResolvesClientScope;
use App\Http\Controllers\Controller;
use App\Services\Scheduling\SchedulingClient;
use App\Services\Scheduling\SchedulingException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MeetingController extends Controller
{
    use ResolvesClientScope;

    /**
     * The meetings staff offer, and what this owner already has booked.
     *
     * Booking itself happens on tx-scheduling's own page, which already knows
     * about timezones, the questions each meeting asks, extra guests, and
     * rescheduling. The portal lists what is on offer and reads back what the
     * owner booked, matched on their email address.
     */
    public function index(Request $request, SchedulingClient $scheduling): Response
    {
        $account = $this->activeClientAccount($request, ClientRole::Owner);
        abort_if($account === null, 403);

        $email = (string) $request->user()->email;

        return Inertia::render('owner/Meetings', [
            'available' => $scheduling->isConfigured(),
            'eventTypes' => Inertia::defer(function () use ($scheduling): array {
                try {
                    return $scheduling->eventTypes();
                } catch (SchedulingException) {
                    return [];
                }
            }),
            'upcoming' => Inertia::defer(function () use ($scheduling, $email): array {
                try {
                    return $scheduling->upcoming($email);
                } catch (SchedulingException) {
                    return [];
                }
            }),
        ]);
    }
}
