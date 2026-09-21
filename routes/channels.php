<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// A client's conversations with TexasRenters.com, for every login linked to that Propertyware contact.
Broadcast::channel('contacts.{contactId}.messages', function (User $user, string $contactId) {
    return $user->clientAccounts()->where('propertyware_id', $contactId)->exists();
});
