<?php

namespace App\Services\Scheduling;

use Carbon\CarbonImmutable;

/**
 * Made-up booked meetings, for seeing the Meetings page on a local copy.
 *
 * Turned on with SCHEDULING_SAMPLE_BOOKINGS, and only ever on a local copy:
 * booking for real would email staff and hold their calendars. Times are
 * counted from today in Houston, so the list always looks current, and are
 * shaped like coresched's bookings API.
 */
class SampleBookings
{
    public const TIMEZONE = 'America/Chicago';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function upcoming(string $email, ?string $manageUrl = null): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();

        $meetings = [
            ['Accounting Team Meeting - Phone Call', 1, '10:00', 'Phone call (host calls invitee)', null],
            ['Leasing Team Meeting - Screen Share', 3, '14:30', 'Microsoft Teams', 'https://teams.microsoft.com/l/meetup-join/sample'],
            ['Maintenance Team Meeting - Phone Call', 6, '09:00', 'Phone call (host calls invitee)', null],
            ['New To Market Meeting - Screen Share', 10, '11:00', 'Microsoft Teams', 'https://teams.microsoft.com/l/meetup-join/sample'],
        ];

        return array_map(function (array $meeting, int $index) use ($today, $email, $manageUrl): array {
            [$name, $days, $time, $location, $meetingUrl] = $meeting;
            [$hour, $minute] = array_map('intval', explode(':', $time));
            $starts = $today->addDays($days)->setTime($hour, $minute);

            return [
                'id' => 900000 + $index,
                'uid' => 'sample-'.($index + 1),
                'name' => $name,
                'startsAt' => $starts->utc()->toIso8601String(),
                'endsAt' => $starts->addMinutes(20)->utc()->toIso8601String(),
                'timezone' => self::TIMEZONE,
                'locationLabel' => $location,
                'meetingUrl' => $meetingUrl,
                'manageUrl' => $manageUrl ?? 'https://coresched.com',
                'email' => $email,
            ];
        }, $meetings, array_keys($meetings));
    }
}
