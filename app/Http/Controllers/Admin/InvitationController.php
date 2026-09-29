<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\FlashesToasts;
use App\Http\Controllers\Controller;
use App\Models\ClientInvitation;
use App\Services\Admin\AdminAudit;
use App\Services\Admin\InvitationDesk;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inviting Propertyware tenants and owners to the portal, and following up
 * on the links that were sent.
 */
class InvitationController extends Controller
{
    use FlashesToasts;

    public function __construct(private InvitationDesk $desk, private AdminAudit $audit) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:all,pending,accepted,expired,cancelled'],
            'search' => ['nullable', 'string', 'max:100'],
            'lookup' => ['nullable', 'email', 'max:255'],
        ]);
        $status = $validated['status'] ?? 'all';
        $search = trim((string) ($validated['search'] ?? ''));

        return Inertia::render('admin/Invitations', [
            'status' => $status,
            'search' => $search,
            'counts' => $this->desk->counts(),
            'invitations' => $this->desk->paginate($status === 'all' ? null : $status, $search === '' ? null : $search),
            // Only asked for by the invite dialog, as the email is typed.
            'contacts' => Inertia::optional(fn (): array => $this->lookup($validated['lookup'] ?? null)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(ClientRole::class)],
            'propertyware_id' => ['required', 'string', 'max:50'],
        ]);

        $invitation = $this->desk->invite(
            $request->user(),
            $validated['email'],
            $validated['name'],
            ClientRole::from($validated['role']),
            $validated['propertyware_id'],
        );

        $this->audit->record($request->user(), 'invitation_sent', "Invited {$invitation->email} to the {$invitation->role->value} portal", details: ['propertyware_id' => $invitation->propertyware_id]);
        $this->toast("Invitation sent to {$invitation->email}.");

        return back();
    }

    /**
     * Send a fresh link; the old one stops working.
     */
    public function resend(Request $request, ClientInvitation $invitation): RedirectResponse
    {
        if ($invitation->accepted_at !== null) {
            $this->toast('That invitation was already accepted.', 'error');

            return back();
        }

        $this->desk->resend($invitation, $request->user());
        $this->audit->record($request->user(), 'invitation_resent', "Sent {$invitation->email} a new invitation link");
        $this->toast("A new link is on its way to {$invitation->email}.");

        return back();
    }

    /**
     * Withdraw a link before it is used.
     */
    public function destroy(Request $request, ClientInvitation $invitation): RedirectResponse
    {
        if ($invitation->status() !== 'pending') {
            $this->toast('Only a pending invitation can be cancelled.', 'error');

            return back();
        }

        $this->desk->cancel($invitation);
        $this->audit->record($request->user(), 'invitation_cancelled', "Cancelled the invitation to {$invitation->email}");
        $this->toast('Invitation cancelled. The link no longer works.');

        return back();
    }

    /**
     * @return array{email: ?string, results: array<int, array<string, mixed>>, error: ?string}
     */
    private function lookup(?string $email): array
    {
        if (blank($email)) {
            return ['email' => null, 'results' => [], 'error' => null];
        }

        try {
            return ['email' => $email, 'results' => $this->desk->contactsForEmail($email), 'error' => null];
        } catch (PropertywareException) {
            return ['email' => $email, 'results' => [], 'error' => 'Propertyware could not be reached. Enter the contact ID by hand, or try again.'];
        }
    }
}
