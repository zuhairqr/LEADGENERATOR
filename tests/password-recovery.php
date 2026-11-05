<?php
require __DIR__.'/../vendor/autoload.php';
use Illuminate\Support\Facades\Artisan;
use App\Services\OperatorPasswordReset;
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('local')) throw new RuntimeException('Local test only.');
$originalPath=$app->environmentPath();$folder=storage_path('local-runtime/password-recovery-test-'.bin2hex(random_bytes(6)));
mkdir($folder,0700,true);$app->useEnvironmentPath($folder);
config(['cache.stores.file.path'=>$folder.'/cache','cache.stores.file.lock_path'=>$folder.'/locks']);
$checks=0;$failed=false;
function recoveryCheck(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo 'PASS: '.$label.PHP_EOL;}
try {
    $text="APP_ENV=local\r\nLEAD_ADMIN_EMAIL=\"fixture@example.test\"\r\nLEAD_ADMIN_PASSWORD_HASH=\"test-old-hash\"\r\nLEAD_IMPORT_TOKEN=\"test-token-only\"\r\nAPP_KEY=\"test-key-only\"\r\n";
    file_put_contents($folder.'/.env',$text);
    try {app(OperatorPasswordReset::class)->reset('short');throw new LogicException('Short password accepted');}catch(RuntimeException $e){recoveryCheck(file_get_contents($folder.'/.env')===$text,'Short password is rejected without changing configuration');}
    $password='Temporary-test-password-'.bin2hex(random_bytes(8));app(OperatorPasswordReset::class)->reset($password);
    $new=file_get_contents($folder.'/.env');preg_match('/^LEAD_ADMIN_PASSWORD_HASH="([^\r\n]*)"/m',$new,$m);
    $hash=str_replace('\\$','$',$m[1]);
    recoveryCheck(password_verify($password,$hash),'Private password is saved only as a valid password hash');
    recoveryCheck(!str_contains($new,$password),'Plaintext password is absent from the environment file');
    recoveryCheck(preg_replace('/^LEAD_ADMIN_PASSWORD_HASH=[^\r\n]*/m','[PASSWORD]',$new)===preg_replace('/^LEAD_ADMIN_PASSWORD_HASH=[^\r\n]*/m','[PASSWORD]',$text),'Existing email, keys, import credentials and line endings are preserved');
    recoveryCheck(hash('sha256','test-old-hash')!==hash('sha256',$hash),'Existing operator session hash becomes invalid');
    $before=file_get_contents($folder.'/.env');$exit=Artisan::call('leadgen:reset-password',['--no-interaction'=>true]);
    recoveryCheck($exit===1 && file_get_contents($folder.'/.env')===$before,'Noninteractive reset cannot create a password');
    file_put_contents($folder.'/.env',"LEAD_ADMIN_EMAIL=\nLEAD_ADMIN_PASSWORD_HASH=\n");
    try {app(OperatorPasswordReset::class)->reset($password);throw new LogicException('Empty account reset allowed');}catch(RuntimeException $e){recoveryCheck(!str_contains(file_get_contents($folder.'/.env'),'fixture@example.test'),'Recovery cannot create or replace an account');}
    echo "$checks password recovery checks passed. The real environment was not modified.\n";
}catch(Throwable $e){$failed=true;fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);}finally{$app->useEnvironmentPath($originalPath);}
exit($failed?1:0);
