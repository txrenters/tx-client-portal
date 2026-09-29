<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StaffRole;
use App\Http\Controllers\Concerns\FlashesToasts;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AdminAudit;
use App\Services\Admin\StaffDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Adding, changing and removing staff. Only owners and admins get here, and
 * only after confirming their password.
 */
class StaffController extends Controller
{
    use FlashesToasts;

    public function __construct(private StaffDirectory $directory, private AdminAudit $audit) {}

    public function index(Request $request): Response
    {
        return Inertia::render('admin/Staff', [
            'staff' => $this->directory->all(),
            'currentUserId' => $request->user()->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(StaffRole::class)],
        ]);
        $role = StaffRole::from($validated['role']);

        $user = $this->directory->grant($validated['email'], $validated['name'], $role);

        $this->audit->record($request->user(), 'staff_granted', "Gave {$user->email} {$role->label()} access", $user);
        $this->toast("{$user->email} now has {$role->label()} access. They have been emailed.");

        return back();
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate(['role' => ['required', Rule::enum(StaffRole::class)]]);
        $role = StaffRole::from($validated['role']);

        $this->directory->changeRole($request->user(), $user, $role);

        $this->audit->record($request->user(), 'staff_role_changed', "Made {$user->email} {$role->label()}", $user);
        $this->toast("{$user->email} is now {$role->label()}.");

        return back();
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->directory->revoke($request->user(), $user);

        $this->audit->record($request->user(), 'staff_revoked', "Removed staff access from {$user->email}", $user);
        $this->toast("{$user->email} no longer has staff access.");

        return back();
    }
}
