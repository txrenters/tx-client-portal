<?php

namespace App\Services\Demo;

use App\Models\DemoRecord;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * The messaging hub for the demo logins: their made-up threads plus what the
 * reviewer wrote. Nothing reaches the hub, so staff never see demo messages.
 */
final class DemoChatHub
{
    public function __construct(private DemoData $data) {}

    public function respond(Request $request): PromiseInterface
    {
        $path = (string) preg_replace('#^api/v1/#', '', trim((string) parse_url($request->url(), PHP_URL_PATH), '/'));
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $segments = explode('/', $path);
        $id = (int) ($segments[1] ?? 0);

        return match (true) {
            $request->method() === 'GET' && $path === 'threads' => $this->list((string) ($query['contact_id'] ?? ''), (int) ($query['page'] ?? 1)),
            $request->method() === 'GET' && $segments[0] === 'threads' && count($segments) === 2 => $this->show($id),
            $request->method() === 'GET' && $segments[0] === 'threads' && ($segments[2] ?? null) === 'messages' => Http::response(['data' => $this->messages($id)
                ->filter(fn (array $message): bool => $message['id'] > (int) ($query['after_id'] ?? 0))->values()->all()]),
            $request->method() === 'POST' && $path === 'threads' => Http::response(['data' => $this->open($this->fields($request))], 201),
            $request->method() === 'POST' && $segments[0] === 'threads' && ($segments[2] ?? null) === 'messages' => $this->post($id, $this->fields($request)),
            $segments[0] === 'contacts' && count($segments) === 2 => Http::response(['data' => [
                'contact_id' => $segments[1],
                'sms_replies' => (bool) ($this->fields($request)['sms_replies'] ?? false),
            ]]),
            default => Http::response(['message' => 'Not found.'], 404),
        };
    }

    private function list(string $contactId, int $page): PromiseInterface
    {
        $threads = $page === 1 ? $this->threads()->where('contact_id', $contactId)->values()->all() : [];

        return Http::response(['data' => $threads, 'meta' => ['current_page' => $page, 'last_page' => 1, 'total' => count($threads)]]);
    }

    private function show(int $id): PromiseInterface
    {
        $thread = $this->threads()->firstWhere('id', $id);

        return $thread === null ? Http::response(['message' => 'Not found.'], 404) : Http::response(['data' => $thread]);
    }

    /**
     * Threads with their last message and when they last changed, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function threads(): Collection
    {
        return collect([...$this->data->chatThreads(), ...DemoRecord::payloads('chat_thread')])
            ->map(function (array $thread): array {
                $last = $this->messages($thread['id'])->last();

                return [...$thread, 'last_message' => $last, 'updated_at' => $last['created_at'] ?? $thread['created_at'] ?? now()->toIso8601String()];
            })
            ->sortByDesc('updated_at')
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function messages(int $threadId): Collection
    {
        return collect([...$this->data->chatMessages(), ...DemoRecord::payloads('chat_message')])
            ->where('thread_id', $threadId)
            ->sortBy('id')
            ->values();
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function open(array $fields): array
    {
        return DemoRecord::store('chat_thread', fn (int $id): array => [
            'id' => $id, 'status' => 'open',
            'subject' => $fields['subject'] ?? ucfirst((string) ($fields['category'] ?? 'general')).' question',
            'type' => $fields['category'] ?? null, 'contact_id' => (string) ($fields['contact_id'] ?? ''), 'phone' => null,
            'work_order_id' => null, 'work_order_number' => null, 'assigned_to' => null, 'assigned_to_photo_url' => null,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function post(int $threadId, array $fields): PromiseInterface
    {
        if ($this->threads()->firstWhere('id', $threadId) === null) {
            return Http::response(['message' => 'Not found.'], 404);
        }

        $message = DemoRecord::store('chat_message', fn (int $id): array => [
            'id' => $id, 'thread_id' => $threadId, 'direction' => 'inbound', 'sender' => 'user', 'sender_name' => null,
            'sender_photo_url' => null, 'body' => $fields['body'] ?? null, 'media_urls' => [], 'sender_number' => null,
            'created_at' => now()->toIso8601String(),
        ]);

        return Http::response(['data' => ['id' => $message['id']]], 201);
    }

    /**
     * The request's fields, whether sent as JSON or, with an attachment, as multipart.
     *
     * @return array<string, mixed>
     */
    private function fields(Request $request): array
    {
        if (! $request->isMultipart()) {
            return $request->data();
        }

        return collect($request->data())
            ->reject(fn (array $part): bool => isset($part['filename']))
            ->mapWithKeys(fn (array $part): array => [$part['name'] => (string) $part['contents']])
            ->all();
    }
}
