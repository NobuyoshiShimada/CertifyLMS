<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Auth\Access\HandlesAuthorization;

class DatabaseNotificationPolicy
{
    use HandlesAuthorization;

    /**
     * Create a new policy instance.
     */
    public function update(User $user, DatabaseNotification $notification): bool
    {
        return $notification->notifiable_id === $user->id
        && $notification->notifiable_type === get_class($user);
    }
}
