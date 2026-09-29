<?php

namespace App\Enums;

/**
 * What a help request is about. Only problems with the portal and the apps:
 * anything about a property goes through Messages or Maintenance instead.
 */
enum HelpTopic: string
{
    case SigningIn = 'signing_in';
    case AccountAccess = 'account_access';
    case SomethingBroken = 'something_broken';
    case Notifications = 'notifications';
    case Other = 'other';

    /**
     * The label clients and staff see.
     */
    public function label(): string
    {
        return match ($this) {
            self::SigningIn => 'Signing in or my password',
            self::AccountAccess => 'I can\'t see my property or account',
            self::SomethingBroken => 'Something isn\'t working',
            self::Notifications => 'Notifications or emails',
            self::Other => 'Something else about the app',
        };
    }

    /**
     * Every topic as the forms list them.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $topic): array => ['value' => $topic->value, 'label' => $topic->label()],
            self::cases(),
        );
    }
}
