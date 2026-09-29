<?php

namespace App\Services\Admin;

use App\Actions\Client\InviteClient;
use App\Enums\ClientRole;
use App\Models\ClientAccount;
use App\Models\ClientInvitation;
use App\Models\User;
use App\Services\Propertyware\Data\Contact;
use App\Services\Propertyware\PropertywareClient;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invitations to the portal, for staff: who was asked, whether they came,
 * and sending, resending or withdrawing a link.
 *
 * The same checks as the client:invite command, so an admin cannot invite a
 * Propertyware record someone else already holds, or grant the wrong portal.
 */
class InvitationDesk
{
    /** Invitations on one page of the list. */
    private const PER_PAGE = 25;

    public function __construct(
        private InviteClient $inviteClient,
        private PropertywareClient $propertyware,
        private PropertywareDirectory $directory,
    ) {}

    /**
     * Invitations, newest first, optionally one status or a search.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(?string $status = null, ?string $search = null): LengthAwarePaginator
    {
        return ClientInvitation::query()
            ->with('inviter:id,email')
            ->when($status === 'pending', fn ($query) => $query->pending())
            ->when($status === 'accepted', fn ($query) => $query->whereNotNull('accepted_at'))
            ->when($status === 'cancelled', fn ($query) => $query->whereNull('accepted_at')->whereNotNull('cancelled_at'))
            ->when($status === 'expired', fn ($query) => $query
                ->whereNull('accepted_at')->whereNull('cancelled_at')->where('expires_at', '<=', now()))
            ->when(filled($search), function ($query) use ($search): void {
                $term = '%'.mb_strtolower((string) $search).'%';

                $query->where(function ($query) use ($term): void {
                    $query->whereRaw('lower(email) like ?', [$term])
                        ->orWhereRaw('lower(name) like ?', [$term]);
                });
            })
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (ClientInvitation $invitation): array => $this->present($invitation));
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ClientInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'name' => $invitation->name,
            'role' => $invitation->role->value,
            'propertywareId' => $invitation->propertyware_id,
            'status' => $invitation->status(),
            'sentAt' => $invitation->created_at?->toIso8601String(),
            'expiresAt' => $invitation->expires_at?->toIso8601String(),
            'acceptedAt' => $invitation->accepted_at?->toIso8601String(),
            'cancelledAt' => $invitation->cancelled_at?->toIso8601String(),
            'invitedBy' => $invitation->inviter?->email,
        ];
    }

    /**
     * How many invitations are in each state, for the tabs.
     *
     * @return array{pending: int, accepted: int, expired: int}
     */
    public function counts(): array
    {
        return [
            'pending' => ClientInvitation::query()->pending()->count(),
            'accepted' => ClientInvitation::query()->whereNotNull('accepted_at')->count(),
            'expired' => ClientInvitation::query()
                ->whereNull('accepted_at')->whereNull('cancelled_at')->where('expires_at', '<=', now())->count(),
        ];
    }

    /**
     * Every Propertyware tenant or owner using an email address, and whether
     * each one is already on the portal, so staff pick the right record.
     *
     * @return array<int, array{id: string, role: string, name: string, portfolios: int, leases: int, linkedTo: ?string, pendingInvitation: bool}>
     *
     * @throws PropertywareException
     */
    public function contactsForEmail(string $email): array
    {
        $email = Str::lower(trim($email));

        return collect($this->propertyware->listAll('contacts', ['email' => $email], maxRecords: 200))
            ->map(fn (array $record): Contact => Contact::fromArray($record))
            // Propertyware does not always honour the filter, so check it here.
            ->filter(fn (Contact $contact): bool => $contact->role !== null && Str::lower(trim($contact->email ?? '')) === $email)
            ->map(fn (Contact $contact): array => [
                'id' => $contact->id,
                'role' => $contact->role->value,
                'name' => $contact->fullName(),
                'portfolios' => count($contact->portfolioIds),
                'leases' => count($contact->leaseIds),
                ...$this->portalStatus($contact->role, $contact->id),
            ])
            ->values()
            ->all();
    }

    /**
     * Whether a Propertyware record is on the portal yet: the login holding
     * it, or a link waiting to be used.
     *
     * @return array{linkedTo: ?string, linkedUserId: ?int, pendingInvitation: bool}
     */
    public function portalStatus(ClientRole $role, string $contactId): array
    {
        $account = ClientAccount::query()
            ->with('user:id,email')
            ->where('role', $role->value)
            ->where('propertyware_id', $contactId)
            ->first();

        return [
            'linkedTo' => $account?->user?->email,
            'linkedUserId' => $account?->user?->id,
            'pendingInvitation' => ClientInvitation::query()->pending()
                ->where('role', $role->value)->where('propertyware_id', $contactId)->exists(),
        ];
    }

    /**
     * Invite a Propertyware tenant or owner, after the same checks the
     * command makes.
     *
     * @throws ValidationException
     */
    public function invite(User $admin, string $email, string $name, ClientRole $role, string $contactId): ClientInvitation
    {
        $email = Str::lower(trim($email));

        if (ClientAccount::query()->where('role', $role->value)->where('propertyware_id', $contactId)->exists()) {
            throw ValidationException::withMessages(['propertyware_id' => __('This Propertyware :role is already linked to a portal login.', ['role' => $role->value])]);
        }

        if (ClientInvitation::query()->pending()->where('role', $role->value)->where('propertyware_id', $contactId)->exists()) {
            throw ValidationException::withMessages(['propertyware_id' => __('There is already a pending invitation for this record. Resend it instead.')]);
        }

        try {
            $contact = $this->directory->findContact($contactId);
        } catch (PropertywareException) {
            throw ValidationException::withMessages(['propertyware_id' => __('Propertyware could not be reached to check this record. Try again in a moment.')]);
        }

        if ($contact === null) {
            throw ValidationException::withMessages(['propertyware_id' => __('No Propertyware contact has that ID.')]);
        }

        if ($contact->role !== $role) {
            throw ValidationException::withMessages(['role' => __('That Propertyware contact is not a :role.', ['role' => $role->value])]);
        }

        return $this->inviteClient->handle($email, $name, $role, $contactId, $admin);
    }

    /**
     * Send a fresh link for an invitation that was not used, withdrawing the
     * old one so only the newest works.
     */
    public function resend(ClientInvitation $invitation, User $admin): ClientInvitation
    {
        if ($invitation->accepted_at === null && $invitation->cancelled_at === null) {
            $invitation->forceFill(['cancelled_at' => now()])->save();
        }

        return $this->inviteClient->handle($invitation->email, $invitation->name, $invitation->role, (string) $invitation->propertyware_id, $admin);
    }

    /**
     * Withdraw a link that has not been used.
     */
    public function cancel(ClientInvitation $invitation): void
    {
        $invitation->forceFill(['cancelled_at' => now()])->save();
    }
}
