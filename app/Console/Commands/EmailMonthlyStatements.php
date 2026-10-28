<?php

namespace App\Console\Commands;

use App\Enums\ClientRole;
use App\Models\ClientAccount;
use App\Models\OwnerStatementEmail;
use App\Notifications\MonthlyStatementNotification;
use App\Services\Propertyware\IncomeStatement;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('statements:email-monthly {--month= : The month to send, as YYYY-MM; last month by default}')]
#[Description('Email last month\'s income statement to owners who turned monthly statements on')]
class EmailMonthlyStatements extends Command
{
    /**
     * One email per owner account, with a PDF for each of their portfolios.
     * An owner whose ledger could not be read is tried again on the next run.
     */
    public function handle(PropertywareDirectory $directory, IncomeStatement $statements): int
    {
        $month = $this->option('month') ?: CarbonImmutable::now(config('app.display_timezone'))->subMonthNoOverflow()->format('Y-m');

        if (! is_string($month) || ! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->error('The month must be written as YYYY-MM.');

            return self::FAILURE;
        }

        $label = CarbonImmutable::createFromFormat('!Y-m', $month)->format('F Y');
        $sent = 0;
        $skipped = 0;

        // The App Review demo logins hold made-up records and a fake
        // Propertyware contact; looking them up would hit the real API. A
        // statement holds the owner's figures, so it only goes to an email
        // address they have confirmed.
        $accounts = ClientAccount::query()
            ->where('role', ClientRole::Owner)
            ->whereHas('user', fn ($user) => $user->where('is_demo', false)->whereNull('suspended_at')->whereNotNull('email_verified_at'))
            ->whereNotIn('id', OwnerStatementEmail::query()->where('month', $month)->select('client_account_id'))
            ->with('user')
            ->get()
            ->filter(fn (ClientAccount $account): bool => $account->user->wantsNotification('monthly_statement_email'));

        foreach ($accounts as $account) {
            try {
                $scope = $directory->scopeFor($account);
                $pdfs = array_map(function (int $portfolioId) use ($statements, $scope, $month): array {
                    $report = $statements->for($scope, $portfolioId, $month, $month);

                    return ['name' => $statements->fileName($report), 'contents' => $statements->pdfContents($report)];
                }, $scope->portfolioIds);
            } catch (PropertywareException $exception) {
                Log::warning('Could not make an owner\'s monthly statement; trying again on the next run.', [
                    'client_account_id' => $account->id,
                    'reason' => $exception->getMessage(),
                ]);
                $skipped++;

                continue;
            }

            if ($pdfs === []) {
                continue;
            }

            try {
                $account->user->notify(new MonthlyStatementNotification($label, $pdfs));
            } catch (Throwable $exception) {
                report($exception);
                $skipped++;

                continue;
            }

            OwnerStatementEmail::create(['client_account_id' => $account->id, 'month' => $month, 'portfolios' => count($pdfs)]);
            $sent++;
        }

        $this->info("Emailed {$sent} owner(s) their {$label} statement; {$skipped} left for the next run.");

        return self::SUCCESS;
    }
}
