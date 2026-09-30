<?php

namespace App\Http\Controllers\Owner;

use App\Enums\ClientRole;
use App\Events\MeetingsUpdated;
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
     * Where to book a meeting, and what this owner already has booked.
     *
     * Choosing and booking happen on tx-scheduling's departments page, which
     * already knows about each team, timezones, the questions each meeting
     * asks, extra guests, and rescheduling. The portal links there and reads
     * back what the owner booked, matched on their email address.
     */
    public function index(Request $request, SchedulingClient $scheduling): Response
    {
        $account = $this->activeClientAccount($request, ClientRole::Owner);
        abort_if($account === null, 403);

        $email = (string) $request->user()->email;

        return Inertia::render('owner/Meetings', [
            'available' => $scheduling->isConfigured(),
            'bookingUrl' => config('services.scheduling.booking_url'),
            // Scheduling's webhook says here when a booking changes.
            'channel' => MeetingsUpdated::channelFor($request->user()->id),
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
