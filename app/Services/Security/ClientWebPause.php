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

    public const MESSAGE = "We're still in beta, so sign-in isn't open yet. Please check back soon.";

    public const SIGN_UP_MESSAGE = "We're still in beta, so sign-up isn't open yet. Please check back soon.";

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
