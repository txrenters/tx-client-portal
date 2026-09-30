<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientRole;
use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Services\Scheduling\MeetingCatalog;
use App\Services\Scheduling\SchedulingClient;
use App\Services\Scheduling\SchedulingException;
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
}
