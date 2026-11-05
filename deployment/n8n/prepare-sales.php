<?php
// Export connection names only; provider/SMTP secrets never leave their stores.
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$mail=app(App\Services\ProspectingProfile::class)->sender();
$file=storage_path('n8n/lead-collector/sales-settings.json');
$health=App\Models\IntegrationSetting::find('mailbox_health')?->data??[];
file_put_contents($file,json_encode(['ai_name'=>app(App\Services\ProspectingProfile::class)->data()['ai_credential_name'] ?? '', 'gmail_name'=>$mail['gmail_credential_name'] ?? '', 'imap_name'=>$mail['imap_credential_name'] ?? '', 'gmail_verified'=>!empty($health['connected'])&&!empty($health['sender_matches'])&&($health['credential_name']??'')===($mail['gmail_credential_name']??'')],JSON_PRETTY_PRINT));
echo "Email credential names prepared privately.\n";
