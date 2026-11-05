<?php
namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class OperatorPasswordReset
{
    public function reset(string $password): void
    {
        if (!app()->runningInConsole() || !app()->environment('local')) throw new \RuntimeException('Local console recovery only.');
        if (strlen($password)<12 || strlen($password)>512) throw new \RuntimeException('Use a password of 12 to 512 characters.');
        $lock=Cache::lock('first-operator-setup',30);
        if (!$lock->get()) throw new \RuntimeException('Account configuration is busy. Try again.');
        try {
            $path=app()->environmentFilePath();
            if (is_link($path) || !is_file($path) || !is_writable($path)) throw new \RuntimeException('Private environment file is unavailable.');
            $original=file_get_contents($path);
            if (!preg_match('/^LEAD_ADMIN_EMAIL=([^\n]*)/m',$original,$email) || trim($email[1]," \t\r\n\"'")===''
                || !preg_match('/^LEAD_ADMIN_PASSWORD_HASH=([^\n]*)/m',$original,$existing) || trim($existing[1]," \t\r\n\"'")==='') throw new \RuntimeException('No existing operator account. Use first-owner setup.');
            $hash=password_hash($password,PASSWORD_DEFAULT);
            $line='LEAD_ADMIN_PASSWORD_HASH="'.str_replace(['\\','"','$'],['\\\\','\\"','\\$'],$hash).'"';
            $changed=preg_replace_callback('/^LEAD_ADMIN_PASSWORD_HASH=[^\r\n]*/m',fn ()=>$line,$original,-1,$count);
            if ($count!==1) throw new \RuntimeException('Expected one configured operator. Nothing changed.');
            if (file_put_contents($path,$changed,LOCK_EX)!==strlen($changed)) {
                file_put_contents($path,$original,LOCK_EX);
                throw new \RuntimeException('Could not save the password.');
            }
            Artisan::call('config:clear');
        } finally { $lock->release(); }
    }
}
