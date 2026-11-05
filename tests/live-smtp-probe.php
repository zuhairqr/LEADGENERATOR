<?php
// Authenticate over TLS using the saved SMTP connection. No MAIL/RCPT/DATA or email.
require __DIR__.'/../vendor/autoload.php';
use App\Models\IntegrationSecret;
use App\Services\ProspectingProfile;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!in_array('--authenticate',$argv,true)) {fwrite(STDERR,"Use --authenticate only when testing the owner's saved connection is authorized.\n");exit(1);}
$result=app(App\Services\SmtpConnectionCheck::class)->check();
file_put_contents(storage_path('n8n/logs/smtp-auth-probe.json'),json_encode($result,JSON_PRETTY_PRINT));
echo json_encode($result).PHP_EOL;exit($result['authenticated']&&$result['tls']&&$result['sender_matches_authenticated_account']?0:1);
