<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * In-platform and email alert (FR-6.3): evaluation finished, new trend,
 * balance warning, sync failure. Text is stored in both languages so the
 * bell shows it in whichever language the user picks.
 */
class PlatformNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $kind  evaluation | trend | balance | sync | design | system
     * @param  string  $level  info | success | warning | danger
     */
    public function __construct(
        public string $kind,
        public string $titleAr,
        public string $titleEn,
        public string $bodyAr = '',
        public string $bodyEn = '',
        public ?string $url = null,
        public ?int $storeId = null,
        public string $level = 'info',
        public bool $email = false,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->email ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ar = ! $notifiable instanceof User || $notifiable->locale === 'ar';

        $mail = (new MailMessage)
            ->subject($ar ? $this->titleAr : $this->titleEn)
            ->line($ar ? $this->bodyAr : $this->bodyEn);

        return $this->url ? $mail->action($ar ? 'فتح في طوق' : 'Open in طوق', url($this->url)) : $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'level' => $this->level,
            'title_ar' => $this->titleAr,
            'title_en' => $this->titleEn,
            'body_ar' => $this->bodyAr,
            'body_en' => $this->bodyEn,
            'url' => $this->url,
            'store_id' => $this->storeId,
        ];
    }
}
