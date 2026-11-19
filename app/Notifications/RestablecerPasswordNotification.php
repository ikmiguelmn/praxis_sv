<?php

namespace App\Notifications;

use App\Services\ParametrosService;
use App\Support\Parametro;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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
            ->greeting('Hola '.$notifiable->name)
            ->line('Recibimos una solicitud para restablecer la contraseña de su cuenta institucional.')
            ->action('Restablecer contraseña', $url)
            ->line("El enlace es de un solo uso y vence en {$minutos} minutos. Si usted no lo solicitó, ignore este mensaje.");
    }
}
