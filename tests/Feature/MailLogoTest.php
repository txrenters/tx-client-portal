<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Symfony\Component\Mime\Part\DataPart;

test('embeds the logo in the email instead of linking to the site', function () {
    config(['mail.default' => 'array']);
    $user = User::factory()->unverified()->create();

    $user->notify(new VerifyEmail);

    $email = app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
    $inlineImages = collect($email->getAttachments())
        ->filter(fn (DataPart $part): bool => $part->getDisposition() === 'inline');
    expect($inlineImages)->toHaveCount(1)
        ->and($email->getHtmlBody())->toContain('src="cid:')
        ->and($email->getHtmlBody())->not->toContain(asset('texas-renters-logo-email.png'));
});
