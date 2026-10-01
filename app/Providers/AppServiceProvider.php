<?php

namespace App\Providers;

use App\Models\User;
use App\Services\ChatHub\ChatHubClient;
use App\Services\Propertyware\DocumentLibrary;
use App\Services\Propertyware\Financials;
use App\Services\Propertyware\LeaseLedger;
use App\Services\Propertyware\LeaseLedgerSource;
use App\Services\Propertyware\LeaseLedgerStore;
use App\Services\Propertyware\MaintenanceSummary;
use App\Services\Propertyware\OwnerLedger;
use App\Services\Propertyware\PropertywareClient;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareSoap;
use App\Services\Push\ExpoPushClient;
use App\Services\Scheduling\SchedulingClient;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PropertywareClient::class, fn (): PropertywareClient => new PropertywareClient(
            config('services.propertyware.base_url'),
            config('services.propertyware.client_id'),
            config('services.propertyware.client_secret'),
            config('services.propertyware.system_id'),
        ));

        $this->app->singleton(ExpoPushClient::class, fn (): ExpoPushClient => new ExpoPushClient(
            (string) config('services.expo.url'),
            config('services.expo.token'),
        ));

        $this->app->singleton(ChatHubClient::class, fn (): ChatHubClient => new ChatHubClient(
            config('services.chat_hub.url'),
            config('services.chat_hub.token'),
            self::tlsVerify(config('services.chat_hub.verify', true)),
        ));

        $this->app->singleton(SchedulingClient::class, fn (): SchedulingClient => new SchedulingClient(
            config('services.scheduling.url'),
            config('services.scheduling.token'),
            config('services.scheduling.page'),
            self::tlsVerify(config('services.scheduling.verify', true)),
        ));

        $this->app->singleton(PropertywareSoap::class, fn (): PropertywareSoap => new PropertywareSoap(
            config('services.propertyware.soap.url'),
            config('services.propertyware.soap.username'),
            config('services.propertyware.soap.password'),
        ));

        $this->app->singleton(Financials::class, fn (): Financials => new Financials(
            $this->app->make(PropertywareClient::class),
            $this->app->make(PropertywareDirectory::class),
            $this->app->make(LeaseLedgerStore::class),
            $this->app->make(OwnerLedger::class),
        ));

        $this->app->singleton(LeaseLedgerSource::class, fn (): LeaseLedgerSource => new LeaseLedgerSource(
            $this->app->make(PropertywareClient::class),
        ));

        $this->app->singleton(LeaseLedgerStore::class, fn (): LeaseLedgerStore => new LeaseLedgerStore(
            $this->app->make(LeaseLedgerSource::class),
        ));

        $this->app->singleton(LeaseLedger::class, fn (): LeaseLedger => new LeaseLedger(
            $this->app->make(LeaseLedgerSource::class),
            $this->app->make(LeaseLedgerStore::class),
        ));

        $this->app->singleton(PropertywareDirectory::class, fn (): PropertywareDirectory => new PropertywareDirectory(
            $this->app->make(PropertywareClient::class),
            config('services.propertyware.directory_cache_seconds'),
        ));

        $this->app->singleton(DocumentLibrary::class, fn (): DocumentLibrary => new DocumentLibrary(
            $this->app->make(PropertywareClient::class),
            $this->app->make(MaintenanceSummary::class),
            $this->app->make(PropertywareDirectory::class),
            config('services.propertyware.cache_seconds'),
        ));

        $this->app->singleton(MaintenanceSummary::class, fn (): MaintenanceSummary => new MaintenanceSummary(
            $this->app->make(PropertywareClient::class),
            $this->app->make(PropertywareDirectory::class),
            config('services.propertyware.cache_seconds'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The ADMIN_EMAILS owners, plus anyone given staff access in the admin
        // area. A suspended login is never staff, whatever its role says.
        Gate::define('admin', fn (User $user): bool => $user->isStaff() && ! $user->isSuspended());

        // Adding and removing staff: owners and admins, not plain staff.
        Gate::define('manage-staff', fn (User $user): bool => $user->canManageStaff() && ! $user->isSuspended());

        // The log viewer package authorises through its own gate; point it at
        // the same rule so there is only one answer to "who is an admin".
        Gate::define('viewLogViewer', fn (?User $user): bool => $user !== null && Gate::forUser($user)->allows('admin'));

        $this->configureDefaults();
        $this->configureEmails();
    }

    /**
     * Word the built-in account emails for the client portal.
     */
    protected function configureEmails(): void
    {
        VerifyEmail::createUrlUsing(fn (User $notifiable): string => URL::temporarySignedRoute(
            'verification.confirm',
            now()->addMinutes(config('auth.verification.expire', 60)),
            ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())],
        ));

        VerifyEmail::toMailUsing(fn (User $notifiable, string $url): MailMessage => (new MailMessage)
            ->subject(__('Confirm your email for the :app client portal', ['app' => config('app.name')]))
            ->greeting(__('Hi :name,', ['name' => $notifiable->name]))
            ->line(__('Thanks for signing up. Confirm this is your email address so we can connect your account to your property records.'))
            ->action(__('Confirm email address'), $url)
            ->line(__('This link expires in :minutes minutes. If you didn\'t create an account, you can ignore this email.', [
                'minutes' => config('auth.verification.expire', 60),
            ])));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * An env TLS setting as Guzzle expects it: a CA bundle path, or whether to verify at all.
     */
    private static function tlsVerify(mixed $verify): bool|string
    {
        return is_string($verify) && ! in_array(strtolower($verify), ['true', 'false', '1', '0'], true)
            ? $verify
            : filter_var($verify, FILTER_VALIDATE_BOOLEAN);
    }
}
