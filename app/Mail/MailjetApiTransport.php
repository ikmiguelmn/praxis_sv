<?php

namespace App\Mail;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

class MailjetApiTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $secretKey,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'mailjet';
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->apiKey === '' || $this->secretKey === '') {
            throw new TransportException('Credenciales de Mailjet no configuradas (MAIL_USERNAME / MAIL_PASSWORD).');
        }

        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $from = $email->getFrom()[0] ?? new Address(
            (string) config('mail.from.address'),
            (string) config('mail.from.name'),
        );

        $payload = [
            'From' => $this->formatAddress($from),
            'To' => array_map($this->formatAddress(...), $email->getTo()),
            'Subject' => (string) $email->getSubject(),
        ];

        if ($email->getCc() !== []) {
            $payload['Cc'] = array_map($this->formatAddress(...), $email->getCc());
        }

        if ($email->getBcc() !== []) {
            $payload['Bcc'] = array_map($this->formatAddress(...), $email->getBcc());
        }

        if ($email->getReplyTo() !== []) {
            $payload['ReplyTo'] = $this->formatAddress($email->getReplyTo()[0]);
        }

        $textBody = $email->getTextBody();
        if (is_string($textBody) && $textBody !== '') {
            $payload['TextPart'] = $textBody;
        }

        $htmlBody = $email->getHtmlBody();
        if (is_string($htmlBody) && $htmlBody !== '') {
            $payload['HTMLPart'] = $htmlBody;
        }

        $inlined = [];
        $attachments = [];

        foreach ($email->getAttachments() as $part) {
            if (! $part instanceof DataPart) {
                continue;
            }

            $item = [
                'ContentType' => $part->getMediaType().'/'.$part->getMediaSubtype(),
                'Filename' => $part->getFilename() ?: ($part->getName() ?: 'adjunto'),
                'Base64Content' => base64_encode($part->getBody()),
            ];

            if ($part->getDisposition() === 'inline') {
                $contentId = $part->getName() ?: $part->getContentId();
                $item['ContentID'] = $contentId;
                $inlined[] = $item;
            } else {
                $attachments[] = $item;
            }
        }

        if ($inlined !== []) {
            $payload['InlinedAttachments'] = $inlined;
        }

        if ($attachments !== []) {
            $payload['Attachments'] = $attachments;
        }

        $response = Http::withBasicAuth($this->apiKey, $this->secretKey)
            ->acceptJson()
            ->timeout(15)
            ->post('https://api.mailjet.com/v3.1/send', [
                'Messages' => [$payload],
            ]);

        if (! $response->successful()) {
            throw new TransportException(sprintf(
                'Error al enviar correo por Mailjet API (%d): %s',
                $response->status(),
                $response->body(),
            ));
        }
    }

    /**
     * @return array{Email: string, Name?: string}
     */
    private function formatAddress(Address $address): array
    {
        $formatted = ['Email' => $address->getAddress()];

        if ($address->getName() !== '') {
            $formatted['Name'] = $address->getName();
        }

        return $formatted;
    }
}
