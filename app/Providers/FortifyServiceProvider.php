<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Services\Admin\SignInLog;
use App\Services\Propertyware\TerminatedLeaseGate;
use App\Services\Security\ClientWebPause;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkeys;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        // Fortify's own check, plus turning away a login staff have suspended,
        // a resident whose lease has ended, or any client while staff have the
        // website paused, before a session is ever started for it.
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where(Fortify::username(), $request->input(Fortify::username()))->first();

            if ($user === null || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }

            if ($user->isSuspended()) {
                app(SignInLog::class)->record($request, 'blocked', $user, null, 'web', 'password');

                throw ValidationException::withMessages([
                    Fortify::username() => __('This account has been suspended. Contact TexasRenters.com for help.'),
                ]);
            }

            if (app(TerminatedLeaseGate::class)->shutsOut($user)) {
                app(SignInLog::class)->record($request, 'blocked', $user, null, 'web', 'password');

                throw ValidationException::withMessages([
                    Fortify::username() => __(TerminatedLeaseGate::MESSAGE),
                ]);
            }

            if (app(ClientWebPause::class)->shutsOut($user)) {
                app(SignInLog::class)->record($request, 'blocked', $user, null, 'web', 'password');

                throw ValidationException::withMessages([
                    Fortify::username() => __(ClientWebPause::MESSAGE),
                ]);
            }

            return $user;
        });

        // Passkey sign-ins on the web and in the apps both ask this before logging in.
        Passkeys::authorizeLoginUsing(function (Request $request, PasskeyUser $user): bool {
            if ($user instanceof User && app(TerminatedLeaseGate::class)->shutsOut($user)) {
                app(SignInLog::class)->record($request, 'blocked', $user, null, $request->is('api/*') ? 'app' : 'web', 'passkey');

                throw ValidationException::withMessages(['credential' => __(TerminatedLeaseGate::MESSAGE)]);
            }

            // The pause is the website's only; a passkey sign-in in the apps carries on.
            if ($user instanceof User && ! $request->is('api/*') && app(ClientWebPause::class)->shutsOut($user)) {
                app(SignInLog::class)->record($request, 'blocked', $user, null, 'web', 'passkey');

                throw ValidationException::withMessages(['credential' => __(ClientWebPause::MESSAGE)]);
            }

            return true;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'canRegister' => Features::enabled(Features::registration()),
            'status' => $request->session()->get('status'),
            'accessPaused' => app(ClientWebPause::class)->isPaused(),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'accessPaused' => app(ClientWebPause::class)->isPaused(),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
