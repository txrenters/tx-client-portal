<?php

namespace App\Services\Propertyware\Data;

use Carbon\CarbonImmutable;

/**
 * A Propertyware conversation: a subject and its thread of comments, kept on
 * a client's contact record or on one of their buildings.
 */
class Conversation
{
    /**
     * @param  array<int, array{id: string, text: string, author: ?string, at: ?CarbonImmutable}>  $comments  Oldest first.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly ?string $subject,
        public readonly ?int $buildingId,
        public readonly ?CarbonImmutable $createdAt,
        public readonly array $comments,
    ) {}

    /**
     * @param  array<string, mixed>  $record
     */
    public static function fromArray(array $record): self
    {
        $comments = collect($record['comments'] ?? [])
            ->filter(fn (mixed $comment): bool => is_array($comment) && filled($comment['text'] ?? null))
            ->map(fn (array $comment): array => [
                'id' => (string) ($comment['id'] ?? ''),
                'text' => trim((string) $comment['text']),
                // The Propertyware login that wrote it; Propertyware sends no full name.
                'author' => filled($comment['createdBy'] ?? null) ? (string) $comment['createdBy'] : null,
                'at' => self::date($comment['createdDateTime'] ?? null),
            ])
            ->sortBy(fn (array $comment): int => $comment['at']?->getTimestamp() ?? 0)
            ->values()
            ->all();

        return new self(
            id: (string) $record['id'],
            type: (string) ($record['type'] ?? ''),
            // Propertyware often ends building subjects with a stray comma.
            subject: filled($record['subject'] ?? null) ? rtrim(trim((string) $record['subject']), ',') : null,
            buildingId: ($record['entityType'] ?? null) === 'Building' ? (int) $record['entityID'] : null,
            createdAt: self::date($record['createdDateTime'] ?? null),
            comments: $comments,
        );
    }

    /**
     * When the conversation last had a message.
     */
    public function updatedAt(): ?CarbonImmutable
    {
        return collect($this->comments)->last()['at'] ?? $this->createdAt;
    }

    /**
     * Whether a search term appears in the subject or any message.
     */
    public function matches(string $search): bool
    {
        return collect([$this->subject, ...array_column($this->comments, 'text')])
            ->filter()
            ->contains(fn (string $value): bool => str_contains(mb_strtolower($value), $search));
    }

    /**
     * Work order numbers mentioned in the messages, such as "work order #39806".
     *
     * @return array<int, string>
     */
    public function mentionedWorkOrderNumbers(): array
    {
        preg_match_all('/#\s?(\d{4,7})\b/', implode("\n", array_column($this->comments, 'text')), $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param  array<int, array{id: string, number: string}>  $workOrders  Mentioned work orders the client may open.
     * @param  array<int, string>  $staffLogins  Lower-case logins whose messages are from TexasRenters.com.
     * @return array<string, mixed>
     */
    public function toArray(?string $property, array $workOrders = [], array $staffLogins = []): array
    {
        $last = collect($this->comments)->last();

        return [
            'id' => $this->id,
            'subject' => $this->subject ?? 'Conversation',
            'about' => $this->buildingId === null ? 'account' : 'property',
            'buildingId' => $this->buildingId,
            'property' => $property,
            'createdAt' => $this->createdAt?->toIso8601String(),
            'updatedAt' => $this->updatedAt()?->toIso8601String(),
            'messageCount' => count($this->comments),
            'preview' => $last === null ? null : mb_strimwidth($last['text'], 0, 160, '…'),
            'messages' => array_map(fn (array $comment): array => [
                'id' => $comment['id'],
                'text' => $comment['text'],
                'author' => $comment['author'],
                'fromClient' => $comment['author'] !== null && ! in_array(strtolower($comment['author']), $staffLogins, true),
                'at' => $comment['at']?->toIso8601String(),
            ], $this->comments),
            'workOrders' => $workOrders,
        ];
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
