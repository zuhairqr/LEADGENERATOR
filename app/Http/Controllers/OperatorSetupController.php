<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class OperatorSetupController {
    private function ensureAvailable(Request $request): void {
        // First owner only, on the local loopback dashboard. Never an account reset.
        abort_unless(app()->environment('local')
            && (preg_match('/^127\./', (string) $request->ip()) || $request->ip() === '::1')
            && in_array($request->getHost(), ['127.0.0.1', 'localhost', '::1'], true)
            && (string) config('leadgen.admin_email') === ''
            && (string) config('leadgen.admin_password_hash') === '', 404);
    }
    public function create(Request $request) {
        $this->ensureAvailable($request);
        return view('operator-setup');
    }
    public function store(Request $request) {
        $this->ensureAvailable($request);
        $validator = Validator::make($request->only(['email','password','password_confirmation']), [
            'email' => ['required','email','max:254'],
            'password' => ['required','string','min:12','max:512','confirmed'],
        ]);
        if ($validator->fails()) return back()->withErrors($validator);
        $values = $validator->validated();
        $lock = Cache::lock('first-operator-setup', 30);
        abort_unless($lock->get(), 409, 'Owner setup is already in progress.');
        try {
            $path = app()->environmentFilePath();
            abort_unless(is_file($path) && !is_link($path) && is_writable($path), 503, 'Local owner setup is unavailable.');
            $contents = file_get_contents($path);
            foreach (['LEAD_ADMIN_EMAIL','LEAD_ADMIN_PASSWORD_HASH'] as $key) {
                if (preg_match('/^'.preg_quote($key, '/').'=([^\n]*)/m', $contents, $match)) {
                    abort_unless(trim($match[1], " \t\r\n\"'") === '', 404);
                }
            }
            $hash = password_hash($values['password'], PASSWORD_DEFAULT);
            unset($values['password'], $values['password_confirmation']);
            foreach (['LEAD_ADMIN_EMAIL' => $values['email'], 'LEAD_ADMIN_PASSWORD_HASH' => $hash] as $key => $value) {
                $line = $key.'="'.str_replace(['\\','"','$'], ['\\\\','\\"','\\$'], $value).'"';
                $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
                $contents = preg_match($pattern, $contents) ? preg_replace_callback($pattern, fn () => $line, $contents) : rtrim($contents)."\n".$line."\n";
            }
            abort_unless(file_put_contents($path, $contents, LOCK_EX) === strlen($contents), 503, 'Could not save the owner account.');
            Artisan::call('config:clear');
        } finally { $lock->release(); }
        return redirect()->route('login')->with('notice', 'Owner account created. Sign in, then open Connections to add your Facebook and Instagram details.');
    }
}
