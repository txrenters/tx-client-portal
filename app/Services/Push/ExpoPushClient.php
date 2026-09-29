<?php

namespace App\Services\Push;

use App\Models\PushToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends notifications through Expo's push service, which holds our APNs key
 * and FCM credentials and forwards to Apple and Google on our behalf.
 *
 * Expo answers with one ticket per message. A ticket that says the device is
 * no longer registered is the only reliable signal that a phone is gone, so
 * that token is dropped here rather than left to ring nothing forever.
 */
class ExpoPushClient
{
    /** Expo refuses a request carrying more than this many messages. */
    private const BATCH_SIZE = 100;

    public function __construct(
        private string $url = 'https://exp.host',
        private ?string $token = null,
    ) {}

    /**
     * Send one message to each of these devices.
     *
     * @param  iterable<int, PushToken>  $devices
     * @return int how many Expo accepted.
     *
     * @throws ExpoPushException
     */
    public function send(iterable $devices, PushMessage $message): int
    {
        $devices = collect($devices)->keyBy->token;

        if ($devices->isEmpty()) {
            return 0;
        }

        $accepted = 0;

        foreach ($devices->chunk(self::BATCH_SIZE) as $batch) {
            $accepted += $this->sendBatch($batch->all(), $message);
        }

        return $accepted;
    }

    /**
     * @param  array<string, PushToken>  $devices  keyed by token.
     *
     * @throws ExpoPushException
     */
    private function sendBatch(array $devices, PushMessage $message): int
    {
        $tokens = array_keys($devices);
        $tickets = $this->post(array_map(fn (string $token): array => $message->toArray($token), $tokens));
        $accepted = 0;

        foreach ($tickets as $index => $ticket) {
            $token = $tokens[$index] ?? null;

            if ($token === null) {
                continue;
            }

            if (($ticket['status'] ?? null) === 'ok') {
                $accepted++;

                continue;
            }

            $this->handleFailedTicket($devices[$token], $ticket);
        }

        PushToken::query()->whereIn('token', $tokens)->update(['last_pushed_at' => now()]);

        return $accepted;
    }

    /**
     * @param  array<string, mixed>  $ticket
     */
    private function handleFailedTicket(PushToken $device, array $ticket): void
    {
        $error = $ticket['details']['error'] ?? null;

        // Expo is telling us the app is gone from that phone. Keeping the token
        // only means sending into the void on every future notification.
        if ($error === 'DeviceNotRegistered') {
            $device->delete();

            return;
        }

        Log::warning('Expo refused a push notification.', [
            'push_token_id' => $device->getKey(),
            'error' => $error,
            'message' => $ticket['message'] ?? null,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>> one ticket per message, in order.
     *
     * @throws ExpoPushException
     */
    private function post(array $messages): array
    {
        try {
            $request = Http::acceptJson()
                ->withHeaders(['Accept-Encoding' => 'gzip, deflate'])
                ->timeout(15)
                ->connectTimeout(5)
                ->retry(2, 200, throw: false);

            // An access token is optional, and only worth setting once the
            // project has "enhanced security" turned on in its Expo settings.
            $response = ($this->token === null || $this->token === '' ? $request : $request->withToken($this->token))
                ->post(rtrim($this->url, '/').'/--/api/v2/push/send', $messages);
        } catch (ConnectionException $exception) {
            throw new ExpoPushException('Could not reach the Expo push service.', previous: $exception);
        }

        if ($response->failed()) {
            throw new ExpoPushException("The Expo push service answered {$response->status()}.");
        }

        return $response->json('data') ?? [];
    }
}
