<?php
namespace App\Services;
use App\Models\{IntegrationSecret,IntegrationSetting};
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class SmtpConnectionCheck
{
    protected function transport(array $smtp): EsmtpTransport {
        $transport=new EsmtpTransport($smtp['host'],(int)$smtp['port'],($smtp['security'] ?? 'starttls')==='smtps');
        $transport->setRequireTls(true);$transport->setUsername($smtp['username']);$transport->setPassword($smtp['password']);
        $transport->getStream()->setTimeout(15);return $transport;
    }
    private function fingerprint(array $smtp,array $sender):string {
        return hash_hmac('sha256',json_encode([$smtp,$sender]),(string)config('app.key'));
    }
    public function check():array {
        $smtp=IntegrationSecret::find('smtp')?->data ?? [];$sender=app(ProspectingProfile::class)->sender();$transport=null;
        $version=$this->fingerprint($smtp,$sender);
        $result=['authenticated'=>false,'tls'=>false,'sender_matches_authenticated_account'=>false,'emails_sent'=>0,'checked_at'=>now()->toIso8601String()];
        try {
            if (($sender['provider'] ?? '')!=='smtp' || empty($smtp['host']) || empty($smtp['username']) || empty($smtp['password'])) throw new \RuntimeException('INCOMPLETE_CONFIGURATION');
            $transport=$this->transport($smtp);$transport->start();
            $result['authenticated']=true;$result['tls']=$transport->getStream()->isTLS();
            $result['sender_matches_authenticated_account']=strcasecmp($sender['from_email'] ?? '',$smtp['username'])===0;
        } catch (\Throwable $e) {
            // Authentication errors can include transcripts: retain only fixed safe error codes.
            $message=$e->getMessage();
            $result['error_code']=$message==='INCOMPLETE_CONFIGURATION'?'INCOMPLETE_CONFIGURATION':(preg_match('/(?:535|534|authentication|authenticate|username|password)/i',$message)?'SMTP_AUTHENTICATION_REJECTED':(preg_match('/certificate|TLS|SSL/i',$message)?'SMTP_TLS_FAILED':'SMTP_CONNECTION_FAILED'));
        } finally { if ($transport) try {$transport->stop();}catch(\Throwable $e){} }
        if ($version!==$this->fingerprint(IntegrationSecret::find('smtp')?->data ?? [],app(ProspectingProfile::class)->sender())) {
            $result['authenticated']=false;$result['error_code']='CONNECTION_CHANGED_RETEST';return $result;
        }
        IntegrationSetting::updateOrCreate(['key'=>'smtp_health'],['data'=>$result]);
        return $result;
    }
}
