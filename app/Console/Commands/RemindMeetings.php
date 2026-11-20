<?php

namespace App\Console\Commands;

use App\Enums\ClientRole;
use App\Models\MeetingReminder;
use App\Models\User;
use App\Notifications\MeetingReminderNotification;
use App\Services\Scheduling\SchedulingClient;
use App\Services\Scheduling\SchedulingException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('meetings:remind')]
#[Description('Remind owners an hour and five minutes before a meeting booked in coresched')]
class RemindMeetings extends Command
{
    /** How far ahead the hour reminder looks, in minutes. */
    private const HOUR = 60;

    /** How far ahead the five-minute reminder looks, in minutes. */
    private const FIVE_MINUTES = 5;

    /**
     * Runs every minute. Each booking starting within the hour gets the hour
     * reminder once, and within five minutes the five-minute one once. A
     * meeting booked with less than an hour to go still gets the first
     * ("starts in 40 minutes"); one booked with under five gets only the
     * second. Canceled and moved bookings get nothing; the new time of a
     * moved meeting comes back as its own booking and is reminded about.
     */
    public function handle(SchedulingClient $scheduling): int
    {
        if (! $scheduling->isConfigured()) {
            $this->info('Scheduling is not configured; nothing to remind.');

            return self::SUCCESS;
        }

        $now = CarbonImmutable::now();

        try {
            $bookings = $scheduling->bookingsBetween($now, $now->addMinutes(self::HOUR));
        } catch (SchedulingException $exception) {
            // Tried again next minute; nothing is recorded.
            Log::warning('Could not read upcoming bookings for meeting reminders.', ['reason' => $exception->getMessage()]);
            $this->warn('Could not read upcoming bookings: '.$exception->getMessage());

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($bookings as $booking) {
            if (($booking['status'] ?? null) !== 'confirmed' || filled($booking['canceledAt'] ?? null)) {
                continue;
            }

            $uid = (string) ($booking['uid'] ?? '');
            $email = trim((string) ($booking['invitee']['email'] ?? ''));

            if ($uid === '' || $email === '' || blank($booking['startsAt'] ?? null)) {
                continue;
            }

            $startsAt = CarbonImmutable::parse($booking['startsAt'])->utc();
            $minutes = $now->diffInSeconds($startsAt, absolute: false) / 60;

            $kind = match (true) {
                $minutes <= 0 => null,
                $minutes <= self::FIVE_MINUTES => MeetingReminder::FIVE_MINUTES,
                $minutes <= self::HOUR => MeetingReminder::HOUR,
                default => null,
            };

            if ($kind === null) {
                continue;
            }

            foreach ($this->ownersFor($email) as $owner) {
                $sent += $this->remind($owner, $uid, (string) ($booking['eventName'] ?? 'Your meeting'), $startsAt, $kind, $now);
            }
        }

        $this->info("Sent {$sent} meeting reminder(s).");

        return self::SUCCESS;
    }

    /**
     * The owner logins booked under this address. Anyone can book in coresched
     * under any address, so only a login that proved it owns the address is
     * reminded. App Review demo logins and suspended ones never are.
     *
     * @return iterable<int, User>
     */
    private function ownersFor(string $email): iterable
    {
        return User::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->whereNotNull('email_verified_at')
            ->where('is_demo', false)
            ->whereNull('suspended_at')
            ->whereHas('clientAccounts', fn ($accounts) => $accounts->where('role', ClientRole::Owner))
            ->with(['clientAccounts' => fn ($accounts) => $accounts->where('role', ClientRole::Owner)->orderBy('id')])
            ->get();
    }

    /**
     * Send one reminder unless it already went, and record it.
     */
    private function remind(User $owner, string $uid, string $name, CarbonImmutable $startsAt, string $kind, CarbonImmutable $now): int
    {
        $already = MeetingReminder::query()
            ->where('user_id', $owner->id)
            ->where('booking_uid', $uid)
            ->where('kind', $kind)
            ->where('starts_at', $startsAt)
            ->exists();

        if ($already) {
            return 0;
        }

        $owner->notify(new MeetingReminderNotification(
            $owner->clientAccounts->pluck('id')->all(),
            $uid,
            $name,
            $startsAt,
            $kind,
            $now,
        ));

        MeetingReminder::create([
            'user_id' => $owner->id,
            'booking_uid' => $uid,
            'kind' => $kind,
            'starts_at' => $startsAt,
        ]);

        return 1;
    }
}
