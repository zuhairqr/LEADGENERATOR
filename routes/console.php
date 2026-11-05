<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('leadgen:reset-password', function () {
    if (!$this->input->isInteractive() || !app()->environment('local')) {
        $this->error('Run this interactively on the local computer. Password arguments are not accepted.');
        return 1;
    }
    $this->line('Reset only the Lead Generator password. Your account email, leads and n8n connections are preserved.');
    $password=(string)$this->secret('New password (12 or more characters; input is hidden)',false);
    $confirmation=(string)$this->secret('Confirm new password (input is hidden)',false);
    if (!hash_equals($password,$confirmation)) { $this->error('Passwords do not match. Nothing changed.'); return 1; }
    try { app(\App\Services\OperatorPasswordReset::class)->reset($password); }
    catch (\RuntimeException $e) { $this->error($e->getMessage());return 1; }
    finally { unset($password,$confirmation); }
    $this->info('Password updated. Sign in with your existing email and the new password. Previous sessions are invalidated.');
    $this->line('Open http://127.0.0.1:8079/login');
    return 0;
})->purpose('Reset the local operator password with hidden input, preserving the existing email and project data');

Artisan::command('leadgen:setup-operator', function () {
    if (!$this->input->isInteractive()) {
        $this->error('Run this command in your terminal to enter your own email and hidden password.');
        return 1;
    }
    $email = trim((string) $this->ask('Operator email'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        $this->error('Enter a valid email address.');
        return 1;
    }
    $password = (string) $this->secret('Password (at least 12 characters; input is hidden)', false);
    $confirmation = (string) $this->secret('Confirm password (input is hidden)', false);
    if (strlen($password) < 12 || strlen($password) > 512 || !hash_equals($password, $confirmation)) {
        $this->error('Passwords must match and contain 12 to 512 characters. Nothing changed.');
        return 1;
    }
    $path = app()->environmentFilePath();
    if (is_link($path) || !is_file($path) || !is_writable($path)) {
        $this->error('A writable private environment file is required.');
        return 1;
    }
    $contents = file_get_contents($path);
    $values = ['LEAD_ADMIN_EMAIL' => $email, 'LEAD_ADMIN_PASSWORD_HASH' => password_hash($password, PASSWORD_DEFAULT)];
    unset($password, $confirmation);
    foreach ($values as $name => $value) {
        $line = $name.'="'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
        $pattern = '/^'.preg_quote($name, '/').'=.*$/m';
        $contents = preg_match($pattern, $contents)
            ? preg_replace_callback($pattern, fn () => $line, $contents)
            : rtrim($contents)."\n".$line."\n";
    }
    if (file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
        $this->error('Could not save operator configuration.');
        return 1;
    }
    $this->callSilent('config:clear');
    $this->info('Operator configured. Sign in with the credentials you just entered.');
    $this->line('Existing operator sessions will require signing in again.');
    return 0;
})->purpose('Privately configure the single operator account without placing passwords in command history');
