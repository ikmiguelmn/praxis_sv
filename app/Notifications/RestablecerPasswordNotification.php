<?php

namespace App\Notifications;

use App\Services\ParametrosService;
use App\Support\Parametro;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

class RestablecerPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $token,
        public ?int $minutosExpiracion = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('praxis.frontend_url'), '/').'/restablecer-password/'.$this->token;
        $minutos = $this->minutosExpiracion ?? app(ParametrosService::class)->entero(Parametro::RECUPERACION_EXPIRACION_MINUTOS);

        return (new MailMessage)
            ->subject('PRAXIS - Restablecimiento de contraseña')
            ->view(['emails.restablecer-password', 'emails.restablecer-password-texto'], [
                'nombre' => $notifiable->name,
                'url' => $url,
                'minutos' => $minutos,
                'urlInicio' => config('praxis.frontend_url', config('app.url')),
            ])
            ->withSymfonyMessage(function (Email $message): void {
                $logoPath = public_path('images/praxis-logo.png');
                if (is_file($logoPath)) {
                    $message->embedFromPath($logoPath, 'praxis-logo');
                }
            });
    }
}
