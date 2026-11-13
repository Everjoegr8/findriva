<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as Base;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPassword extends Base
{
    public function toMail($notifiable): MailMessage
    {
        $url = rtrim(config('findriva.app_url'), '/').'/reset-password?'.http_build_query([
            'token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Reset your Findriva password')
            ->line('Someone asked to reset the password for this Findriva account.')
            ->action('Choose a new password', $url)
            ->line('This link works for 60 minutes. If you did not ask for this, you can ignore this email; your password stays the same.');
    }
}
