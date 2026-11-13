<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as Base;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class VerifyEmail extends Base
{
    protected function verificationUrl($notifiable): string
    {
        // A signed API link: opening it verifies the address, then sends the person to the dashboard.
        return URL::temporarySignedRoute('verification.verify', Carbon::now()->addHours(48), [
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
        ]);
    }

    public function toMail($notifiable)
    {
        return parent::toMail($notifiable)
            ->subject('Confirm your email for Findriva')
            ->line('This link works for 48 hours.');
    }
}
