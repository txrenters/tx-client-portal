<?php

namespace App\Services\Security;

use App\Models\Setting;
use App\Models\User;

/**
 * Staff pausing client sign-in to the website, for everyone at once.
 *
 * Only the website: the apps carry on, so testers there are not cut off.
 * Staff are never paused, so they can still work and lift the pause.
 */
class ClientWebPause
{
    /** The Setting key the pause is kept under. */
    public const SETTING = 'client_web_paused';

    public const MESSAGE = 'Client sign-in to the website is paused for now. Please try again later.';

    public const SIGN_UP_MESSAGE = 'Sign-up on the website is paused for now. Please try again later.';

    public function isPaused(): bool
    {
        return Setting::valueOf(self::SETTING) === '1';
    }

    public function pause(): void
    {
        Setting::put(self::SETTING, '1');
    }

    public function resume(): void
    {
        Setting::put(self::SETTING, null);
    }

    /**
     * Whether the pause keeps this login off the website right now.
     */
    public function shutsOut(User $user): bool
    {
        return ! $user->isStaff() && $this->isPaused();
    }
}
