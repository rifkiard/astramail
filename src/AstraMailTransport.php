<?php

namespace AstraWorld\AstraMail;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;

class AstraMailTransport extends AbstractTransport
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email      = MessageConverter::toEmail($message->getOriginalMessage());
        $baseUrl    = config('astramail.base_url');
        $clientCode = config('astramail.client_code');
        $verifyTls  = config('astramail.verify_tls', false);

        $data = [
            'from'    => collect($email->getFrom())->map(fn ($a) => $a->getAddress())->join(';'),
            'to'      => collect($email->getTo())->map(fn ($a) => $a->getAddress())->join(';'),
            'subject' => $email->getSubject(),
            'content' => $email->getHtmlBody() ?? $email->getTextBody(),
        ];

        if (count($email->getCc())) {
            $data['cc'] = collect($email->getCc())->map(fn ($a) => $a->getAddress())->join(';');
        }

        if (count($email->getBcc())) {
            $data['bcc'] = collect($email->getBcc())->map(fn ($a) => $a->getAddress())->join(';');
        }

        $request = Http::withOptions(['verify' => $verifyTls])
            ->withHeaders(['X-Client-Code' => $clientCode]);

        // BUG FIX: original code discarded attach() return value (fluent/immutable).
        // Reassign $request on every iteration to accumulate attachments correctly.
        foreach ($email->getAttachments() as $index => $attachment) {
            $request = $request->attach(
                'file' . $index,
                $attachment->getBody(),
                $attachment->getFilename()
            );
        }

        $hasAttachments = count($email->getAttachments()) > 0;

        $response = $hasAttachments
            ? $request->post("{$baseUrl}/send_email", $data)
            : $request->asForm()->post("{$baseUrl}/send_email", $data);

        // Log result for observability.
        logger()->info('AstraMailTransport', [
            'status'   => $response->status(),
            'response' => $response->json(),
            'to'       => $data['to'],
            'subject'  => $data['subject'],
        ]);

        // Surface HTTP errors as exceptions so Laravel mail knows the send failed.
        if ($response->failed()) {
            throw new \RuntimeException(
                "AstraMail send failed [{$response->status()}]: " . $response->body()
            );
        }
    }

    public function __toString(): string
    {
        return 'astramail';
    }
}
