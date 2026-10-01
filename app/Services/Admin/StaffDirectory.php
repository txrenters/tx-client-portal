<?php

namespace App\Services\Admin;

use App\Enums\StaffRole;
use App\Models\User;
use App\Notifications\StaffAccessGrantedNotification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Who can use the admin area.
 *
 * The owners in ADMIN_EMAILS are listed but fixed: they come from config so
 * nothing done inside the app, by a compromised login or anyone else, can
 * take them away. Everyone else is granted admin or staff here, and losing
 * that access never deletes the login.
 */
class StaffDirectory
{
    /**
     * Owners first, then admins and staff by name.
     *
     * @return array<int, array{id: ?int, name: string, email: string, role: string, fixed: bool, lastSeenAt: ?string, twoFactor: bool}>
     */
    public function all(): array
    {
        $owners = collect(config()->array('services.admin_emails'))
            ->map(function (string $email): array {
                $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

                return $this->present($user, $email, 'owner');
            });

        $staff = User::query()
            ->whereNotNull('staff_role')
            ->orderBy('name')
            ->get()
            ->reject(fn (User $user): bool => $user->isOwner())
            ->map(fn (User $user): array => $this->present($user, $user->email, $user->staff_role->value));

        return $owners->merge($staff)->values()->all();
    }

    /**
     * Give someone staff access, creating their login if they have none.
     *
     * A new login is created confirmed, since an admin is vouching for the
     * address, with a random password: the email carries a link to set their
     * own, so nobody ever knows it but them.
     *
     * @throws ValidationException
     */
    public function grant(string $email, string $name, StaffRole $role): User
    {
        $email = Str::lower(trim($email));
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user?->isOwner()) {
            throw ValidationException::withMessages(['email' => __('That address is an owner already, set in ADMIN_EMAILS.')]);
        }

        if ($user?->staff_role !== null) {
            throw ValidationException::withMessages(['email' => __('That person already has staff access. Change their role instead.')]);
        }

        if ($user?->isSuspended()) {
            throw ValidationException::withMessages(['email' => __('That login is suspended. Lift the suspension first.')]);
        }

        $isNew = $user === null;

        if ($isNew) {
            $user = new User(['name' => $name, 'email' => $email, 'password' => Str::password(32)]);
            $user->forceFill(['email_verified_at' => now()]);
        }

        $user->forceFill(['staff_role' => $role])->save();

        $user->notify(new StaffAccessGrantedNotification(
            $role,
            $isNew ? Password::broker()->createToken($user) : null,
        ));

        return $user;
    }

    /**
     * Move someone between admin and staff.
     *
     * @throws ValidationException
     */
    public function changeRole(User $actor, User $user, StaffRole $role): void
    {
        $this->guard($actor, $user);

        $user->forceFill(['staff_role' => $role])->save();
    }

    /**
     * Take staff access away. The login stays, as a client login if it has
     * accounts, or an empty one if not.
     *
     * @throws ValidationException
     */
    public function revoke(User $actor, User $user): void
    {
        $this->guard($actor, $user);

        $user->forceFill(['staff_role' => null])->save();
    }

    /**
     * Owners are changed in config, and nobody changes their own access:
     * that is how an admin locks themselves out, or keeps access they should
     * have lost.
     *
     * @throws ValidationException
     */
    private function guard(User $actor, User $user): void
    {
        if ($user->isOwner()) {
            throw ValidationException::withMessages(['staff' => __('Owners are set in ADMIN_EMAILS and cannot be changed here.')]);
        }

        if ($user->is($actor)) {
            throw ValidationException::withMessages(['staff' => __('You cannot change your own access. Ask another admin.')]);
        }

        if ($user->staff_role === null) {
            throw ValidationException::withMessages(['staff' => __('That person does not have staff access.')]);
        }
    }

    /**
     * @return array{id: ?int, name: string, email: string, role: string, fixed: bool, lastSeenAt: ?string, twoFactor: bool}
     */
    private function present(?User $user, string $email, string $role): array
    {
        return [
            'id' => $user?->id,
            'name' => $user->name ?? $email,
            'email' => $email,
            'role' => $role,
            // Owners come from config; an owner with no login yet has no id.
            'fixed' => $role === 'owner',
            'lastSeenAt' => $user?->last_seen_at?->toIso8601String(),
            'twoFactor' => $user?->two_factor_confirmed_at !== null,
        ];
    }
}
