<?php

namespace App\Http\Controllers\Owner;

use App\Enums\ClientRole;
use App\Events\MeetingsUpdated;
use App\Http\Controllers\Concerns\ResolvesClientScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\BookMeetingRequest;
use App\Http\Requests\Client\MeetingSlotsRequest;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use App\Services\Scheduling\MeetingBookings;
use App\Services\Scheduling\MeetingCatalog;
use App\Services\Scheduling\SchedulingClient;
use App\Services\Scheduling\SchedulingException;
use App\Services\Scheduling\SlotTakenException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
    public function index(Request $request, SchedulingClient $scheduling, MeetingCatalog $catalog, PropertywareDirectory $directory): Response
    {
        $account = $this->activeClientAccount($request, ClientRole::Owner);
        abort_if($account === null, 403);

        $email = (string) $request->user()->email;

        return Inertia::render('owner/Meetings', [
            'available' => $scheduling->isConfigured(),
            'bookingUrl' => config('services.scheduling.booking_url'),
            // Scheduling's webhook says here when a booking changes.
            'channel' => MeetingsUpdated::channelFor($request->user()->id),
            // The meetings staff approved, to book here without leaving the portal.
            'meetingTypes' => Inertia::defer(function () use ($catalog): array {
                try {
                    return $catalog->forClients();
                } catch (SchedulingException) {
                    return [];
                }
            }),
            // Filled into coresched's booking form, for the phone-call meetings.
            'phone' => Inertia::defer(function () use ($directory, $account): ?string {
                try {
                    return $directory->phoneFor($directory->scopeFor($account));
                } catch (PropertywareException) {
                    return null;
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

    /**
     * The open times for one approved meeting, read by the page as the owner
     * moves through the calendar.
     */
    public function slots(MeetingSlotsRequest $request, string $eventType, MeetingBookings $bookings): JsonResponse
    {
        abort_if($this->activeClientAccount($request, ClientRole::Owner) === null, 403);

        try {
            $open = $bookings->slots($eventType, (string) $request->validated('from'), (string) $request->validated('to'));
        } catch (SchedulingException) {
            return response()->json(['message' => 'We could not load the open times. Please try again.'], 503);
        }

        abort_if($open === null, 404);

        return response()->json(['data' => $open['slots']]);
    }

    /**
     * Book an approved meeting for the signed-in owner.
     */
    public function store(BookMeetingRequest $request, MeetingBookings $bookings): RedirectResponse
    {
        abort_if($this->activeClientAccount($request, ClientRole::Owner) === null, 403);

        try {
            $booking = $bookings->book(
                $request->user(),
                (string) $request->validated('event_type'),
                (string) $request->validated('starts_at'),
                $request->validated('notes'),
                $request->validated('timezone'),
            );
        } catch (SlotTakenException $exception) {
            return back()->withErrors(['starts_at' => $exception->getMessage()]);
        } catch (SchedulingException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('We could not book that meeting right now. Please try again.')]);

            return back();
        }

        abort_if($booking === null, 404);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your meeting is booked. We have emailed you the details.')]);

        return to_route('owner.meetings.index');
    }
}
