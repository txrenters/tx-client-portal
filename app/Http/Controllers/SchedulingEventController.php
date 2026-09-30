<?php

namespace App\Http\Controllers;

use App\Enums\ClientRole;
use App\Events\MeetingsUpdated;
use App\Models\User;
use App\Services\Security\IpGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Receives booking events from tx-scheduling and tells the owner's open
 * Meetings page to refresh, in place of the page asking every few seconds.
 *
 * Each event is signed with the shared secret as HMAC-SHA256 of
 * "{timestamp}.{body}"; stale or unsigned events are refused. Scheduling
 * retries a failed delivery with the same delivery id, so a repeat is
 * acknowledged and dropped.
 */
class SchedulingEventController extends Controller
{
    private const TOLERANCE_SECONDS = 300;

    /** Scheduling stops retrying well within a day. */
    private const DELIVERY_MEMORY_HOURS = 24;

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.scheduling.webhook_secret');
        $timestamp = (string) $request->header('X-Bookings-Timestamp');
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        $valid = $secret !== ''
            && ctype_digit($timestamp)
            && hash_equals($expected, (string) $request->header('X-Bookings-Signature'))
            && abs(now()->getTimestamp() - (int) $timestamp) <= self::TOLERANCE_SECONDS;

        if (! $valid) {
            // Someone other than scheduling, or a replay: worth knowing about.
            app(IpGuard::class)->record($request, 'bad_webhook_signature');

            abort(401);
        }

        $deliveryId = (string) ($request->header('X-Bookings-Delivery') ?? $request->input('id'));
        $delivery = $deliveryId === '' ? null : "scheduling:delivery:{$deliveryId}";

        if ($delivery !== null && Cache::has($delivery)) {
            return response()->json(['result' => 'duplicate']);
        }

        $email = strtolower(trim((string) $request->input('booking.invitee.email')));

        // Meetings are for owners only, so a tenant's address is left alone.
        $owners = $email === '' ? collect() : User::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->whereHas('clientAccounts', fn ($accounts) => $accounts->where('role', ClientRole::Owner))
            ->pluck('id');

        foreach ($owners as $userId) {
            MeetingsUpdated::dispatch((int) $userId);
        }

        // Remembered only once handled, so a delivery that failed part way is retried.
        if ($delivery !== null) {
            Cache::put($delivery, true, now()->addHours(self::DELIVERY_MEMORY_HOURS));
        }

        return response()->json(['result' => $owners->isEmpty() ? 'ignored' : 'refreshed']);
    }
}
