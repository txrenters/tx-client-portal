<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\BookMeetingRequest;
use App\Http\Requests\Client\MeetingSlotsRequest;
use App\Models\ClientAccount;
use App\Services\Scheduling\MeetingBookings;
use App\Services\Scheduling\MeetingCatalog;
use App\Services\Scheduling\SchedulingClient;
use App\Services\Scheduling\SchedulingException;
use App\Services\Scheduling\SlotTakenException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeetingController extends Controller
{
    /**
     * The meetings on offer and what this client already has booked.
     */
    public function index(Request $request, ClientAccount $account, SchedulingClient $scheduling, MeetingCatalog $catalog): JsonResponse
    {
        abort_unless($account->role === ClientRole::Owner, 404);

        // The departments page where owners choose and book a meeting.
        $bookingUrl = config('services.scheduling.booking_url');

        if (! $scheduling->isConfigured()) {
            return response()->json(['data' => [], 'meta' => ['available' => false, 'bookingUrl' => $bookingUrl]]);
        }

        try {
            return response()->json([
                // Only the types staff approved on the admin Meetings page.
                'data' => $catalog->forClients(),
                'meta' => [
                    'available' => true,
                    'bookingUrl' => $bookingUrl,
                    'upcoming' => $scheduling->upcoming((string) $request->user()->email),
                ],
            ]);
        } catch (SchedulingException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }
    }

    /**
     * The open times for one approved meeting between two days, for the app's
     * day and time picker. Times are in UTC; the app shows them in Houston time.
     */
    public function slots(MeetingSlotsRequest $request, ClientAccount $account, string $eventType, MeetingBookings $bookings): JsonResponse
    {
        abort_unless($account->role === ClientRole::Owner, 404);

        try {
            $open = $bookings->slots($eventType, (string) $request->validated('from'), (string) $request->validated('to'));
        } catch (SchedulingException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        abort_if($open === null, 404);

        return response()->json([
            'data' => $open['slots'],
            'meta' => [
                'eventType' => $open['type'],
                'from' => $request->validated('from'),
                'to' => $request->validated('to'),
            ],
        ]);
    }

    /**
     * Book an approved meeting for the signed-in owner. Coresched keeps the
     * booking and emails the invitation; its manage page reschedules or cancels.
     */
    public function store(BookMeetingRequest $request, ClientAccount $account, MeetingBookings $bookings): JsonResponse
    {
        abort_unless($account->role === ClientRole::Owner, 404);

        try {
            $booking = $bookings->book(
                $request->user(),
                (string) $request->validated('event_type'),
                (string) $request->validated('starts_at'),
                $request->validated('notes'),
                $request->validated('timezone'),
                $request->validated('name'),
                $request->validated('email'),
            );
        } catch (SlotTakenException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => ['starts_at' => [$exception->getMessage()]]], 409);
        } catch (SchedulingException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        abort_if($booking === null, 404);

        return response()->json(['data' => $booking], 201);
    }
}
