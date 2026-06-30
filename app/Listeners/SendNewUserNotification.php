<?php

namespace App\Listeners;

use App\Mail\NewUserRegistered;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendNewUserNotification implements ShouldQueue
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
