<?php

namespace App\Listeners;

use App\Mail\NewUserRegistered;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Mail;

class SendNewUserNotification
{
    public function handle(Registered $event): void
    {
        $adminEmail = config('mail.admin_address');

        if (! $adminEmail) {
            return;
        }

        Mail::to($adminEmail)->send(new NewUserRegistered($event->user));
    }
}
