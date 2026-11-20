<?php

namespace App\Services\Scheduling;

use App\Models\User;

/**
 * Booking one of the approved meetings from the portal or the app.
 *
 * Only the meeting types staff allow on the admin Meetings page can be read
 * or booked, whatever slug a request names. The booking is made in coresched
 * under the signed-in client's own name and email, so nobody can book for
 * someone else, and coresched sends the invitation and keeps the calendar.
 */
class MeetingBookings
{
    /** The most days one request for open times may cover. */
    public const MAX_DAYS = 31;

    public function __construct(
        private MeetingCatalog $catalog,
        private SchedulingClient $scheduling,
    ) {}

    /**
     * An approved meeting type, or null when clients may not book it.
     *
     * @return array<string, mixed>|null
     *
     * @throws SchedulingException
     */
    public function type(string $slug): ?array
    {
        return collect($this->catalog->forClients())->firstWhere('slug', $slug);
    }

    /**
     * The open times for an approved meeting between two days.
     *
     * @return array{type: array<string, mixed>, slots: array<int, array<string, mixed>>}|null
     *
     * @throws SchedulingException
     */
    public function slots(string $slug, string $from, string $to): ?array
    {
        $type = $this->type($slug);

        if ($type === null) {
            return null;
        }

        return [
            'type' => $type,
            'slots' => $this->scheduling->slots((string) $type['page'], $slug, $from, $to),
        ];
    }

    /**
     * Book an approved meeting for the signed-in client.
     *
     * @return array<string, mixed>|null The booking, or null when the meeting is not one clients may book.
     *
     * @throws SlotTakenException when someone booked the time first.
     * @throws SchedulingException
     */
    public function book(User $client, string $slug, string $startsAt, ?string $notes, ?string $timezone): ?array
    {
        $type = $this->type($slug);

        if ($type === null) {
            return null;
        }

        return $this->scheduling->book((string) $type['page'], $slug, $startsAt, [
            'name' => (string) $client->name,
            'email' => (string) $client->email,
            'notes' => $notes,
            'timezone' => $timezone,
        ]);
    }
}
