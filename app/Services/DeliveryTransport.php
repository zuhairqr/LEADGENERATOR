<?php
namespace App\Services;
use App\Models\{OutboundMessage,IntegrationSecret};
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\{Email,Address};

class DeliveryTransport {
    public function send(OutboundMessage $message, array $settings): array {
        if (($settings['provider'] ?? 'smtp') === 'gmail') {
            $response=Http::withToken((string)config('leadgen.import_token'))->acceptJson()->connectTimeout(5)->timeout(55)
                ->post(rtrim((string)config('leadgen.sales_bridge','http://127.0.0.1:5679/webhook'),'/').'/leadgen-gmail-send',[
                    'messageId'=>$message->id,'recipient'=>$message->recipient,'subject'=>$message->subject,'body'=>$message->body,
                    'fromEmail'=>$message->from_email,'fromName'=>$message->from_name,'parentMessageId'=>$message->parent_id ? OutboundMessage::find($message->parent_id)?->message_id : null]);
            if (!$response->successful() || !$response->json('ok')) throw new \RuntimeException('GMAIL_DELIVERY_UNKNOWN');
            return ['message_id'=>$response->json('message_id'),'thread_id'=>$response->json('thread_id')];
        }
        $smtp=IntegrationSecret::find('smtp')?->data;
        if (!$smtp || empty($smtp['host']) || empty($smtp['password'])) throw new \RuntimeException('SMTP_NOT_CONFIGURED');
        $transport=new EsmtpTransport($smtp['host'],(int)$smtp['port'],($smtp['security'] ?? 'starttls')==='smtps');
        $transport->setRequireTls(true); $transport->setUsername($smtp['username']); $transport->setPassword($smtp['password']);
        $transport->getStream()->setTimeout(20);
        $email=(new Email())->from(new Address($message->from_email,$message->from_name ?? ''))->to($message->recipient)->subject($message->subject)->text($message->body);
        $domain=substr(strrchr($message->from_email,'@'),1); $rfcId=$message->id.'@'.$domain;
        $email->getHeaders()->addIdHeader('Message-ID',$rfcId);
        if ($message->parent_id && ($parent=OutboundMessage::find($message->parent_id)) && $parent->transport==='smtp' && $parent->message_id) {
            $email->getHeaders()->addIdHeader('In-Reply-To',trim($parent->message_id,'<>')); $email->getHeaders()->addIdHeader('References',trim($parent->message_id,'<>'));
        }
        $sent=$transport->send($email); if (!$sent) throw new \RuntimeException('SMTP_DELIVERY_UNKNOWN');
        return ['message_id'=>$sent->getMessageId(),'thread_id'=>null];
    }
}
