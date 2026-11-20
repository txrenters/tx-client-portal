<?php

namespace App\Services\Scheduling;

use App\Models\User;

/**
 * Booking one of the approved meetings from the portal or the app.
 *
 * Only the meeting types staff allow on the admin Meetings page can be read
 * or booked, whatever slug a request names. The booking is made in coresched
 * under the name and email the client gives, the account's own by default,
 * and coresched sends the invitation there and keeps the calendar.
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
    public function book(User $client, string $slug, string $startsAt, ?string $notes, ?string $timezone, ?string $name = null, ?string $email = null): ?array
    {
        $type = $this->type($slug);

        if ($type === null) {
            return null;
        }

        return $this->scheduling->book((string) $type['page'], $slug, $startsAt, [
            'name' => filled($name) ? trim($name) : (string) $client->name,
            'email' => filled($email) ? trim($email) : (string) $client->email,
            'notes' => $notes,
            'timezone' => $timezone,
        ]);
    }
}
