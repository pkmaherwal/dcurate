<?php

namespace App\Notifications;

use App\Models\Source;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SourcePausedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Source $source, public string $error)
    {
        //
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Source paused: :name', ['name' => $this->source->name]))
            ->line(__('":name" in the ":campaign" campaign failed :count times in a row and has been paused.', [
                'name' => $this->source->name,
                'campaign' => $this->source->campaign->name,
                'count' => $this->source->consecutive_errors,
            ]))
            ->line(__('Last error: :error', ['error' => $this->error]))
            ->action(__('Open source health'), route('sources.health'))
            ->line(__('Fix the source (or the site may be down) and press "Retry now".'));
    }
}
