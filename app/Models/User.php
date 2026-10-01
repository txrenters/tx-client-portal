<?php

namespace App\Models;

use App\Enums\ClientRole;
use App\Enums\StaffRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $last_seen_at When they last used the portal or the apps; see TrackLastSeen.
 * @property StaffRole|null $staff_role Staff access granted in the admin area; see isOwner() for the config owners.
 * @property Carbon|null $suspended_at Set by staff to shut the login out; see isSuspended().
 * @property array<string, bool>|null $notification_preferences What the client turned on or off; see notificationPreferences().
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    /**
     * @return HasMany<ClientAccount, $this>
     */
    public function clientAccounts(): HasMany
    {
        return $this->hasMany(ClientAccount::class);
    }

    /**
     * The phones that have asked to be rung for this login.
     *
     * @return HasMany<PushToken, $this>
     */
    public function pushTokens(): HasMany
    {
        return $this->hasMany(PushToken::class);
    }

    /**
     * What the person has asked staff for help with.
     *
     * @return HasMany<HelpRequest, $this>
     */
    public function helpRequests(): HasMany
    {
        return $this->hasMany(HelpRequest::class);
    }

    /**
     * Whether this login is one of the ADMIN_EMAILS owners, who hold every
     * permission and cannot be changed from inside the app.
     */
    public function isOwner(): bool
    {
        return in_array(strtolower($this->email), config('services.admin_emails', []), strict: true);
    }

    /**
     * Whether this login may use the admin area at all.
     */
    public function isStaff(): bool
    {
        return $this->isOwner() || $this->staff_role !== null;
    }

    /**
     * Whether this login may add, change and remove staff.
     */
    public function canManageStaff(): bool
    {
        return $this->isOwner() || $this->staff_role === StaffRole::Admin;
    }

    /**
     * Whether staff have shut this login out of the portal and the apps.
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Get the client roles the user holds, in portal order.
     *
     * @return Collection<int, ClientRole>
     */
    public function clientRoles(): Collection
    {
        return $this->clientAccounts
            ->map(fn (ClientAccount $account): ClientRole => $account->role)
            ->unique()
            ->sortBy(fn (ClientRole $role): int|false => array_search($role, ClientRole::cases(), true))
            ->values();
    }

    /**
     * Get every account the user holds in the given portal.
     *
     * One login can represent several Propertyware records, such as a manager
     * looking after separate owner portfolios.
     *
     * @return Collection<int, ClientAccount>
     */
    public function clientAccountsFor(ClientRole $role): Collection
    {
        return $this->clientAccounts->where('role', $role)->values();
    }

    /**
     * Get the account the user is currently viewing in the given portal.
     */
    public function clientAccountFor(ClientRole $role, ?int $accountId = null): ?ClientAccount
    {
        $accounts = $this->clientAccountsFor($role);

        if ($accountId !== null) {
            return $accounts->firstWhere('id', $accountId) ?? $accounts->first();
        }

        return $accounts->first();
    }

    public function hasClientRole(ClientRole $role): bool
    {
        return $this->clientRoles()->contains($role);
    }

    /**
     * Resolve which portal the user should see, preferring the given role when they hold it.
     */
    public function preferredClientRole(?ClientRole $preferred = null): ?ClientRole
    {
        if ($preferred !== null && $this->hasClientRole($preferred)) {
            return $preferred;
        }

        return $this->clientRoles()->first();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'suspended_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'staff_role' => StaffRole::class,
            'notification_preferences' => 'array',
        ];
    }

    /**
     * Every notification a client can turn off, and whether it is on by default.
     *
     * @var array<string, bool>
     */
    public const NOTIFICATION_DEFAULTS = [
        // Email when TexasRenters.com replies in the portal to a message the client wrote there.
        'message_replies_email' => true,
        // The same reply, pushed to the app. Separate from the email because a
        // client with the app installed usually wants one and not the other.
        'message_replies_push' => true,
    ];

    /**
     * The client's notification settings, with defaults for anything they haven't set.
     *
     * @return array<string, bool>
     */
    public function notificationPreferences(): array
    {
        return [
            ...self::NOTIFICATION_DEFAULTS,
            ...array_intersect_key($this->notification_preferences ?? [], self::NOTIFICATION_DEFAULTS),
        ];
    }

    public function wantsNotification(string $preference): bool
    {
        return $this->notificationPreferences()[$preference] ?? false;
    }
}
