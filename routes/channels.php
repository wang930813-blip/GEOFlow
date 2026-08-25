<?php

use App\Models\Admin;
use App\Models\KeywordLibrary;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('admin.tasks', function (Admin $admin): bool {
    return (string) ($admin->status ?? '') === 'active';
}, ['guards' => ['admin']]);

Broadcast::channel('admin.keyword-libraries.{libraryId}', function (Admin $admin, int $libraryId): bool {
    if ((string) ($admin->status ?? '') !== 'active') {
        return false;
    }

    $library = KeywordLibrary::query()
        ->withoutGlobalScope('current_site')
        ->whereKey($libraryId)
        ->first();

    if (! $library instanceof KeywordLibrary) {
        return false;
    }

    if ($admin->isSuperAdmin()) {
        return true;
    }

    return $admin->sites()
        ->where('sites.id', (int) $library->site_id)
        ->exists();
}, ['guards' => ['admin']]);
