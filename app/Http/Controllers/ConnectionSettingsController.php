<?php
namespace App\Http\Controllers;

use App\Models\IntegrationSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ConnectionSettingsController {
    private const FIELDS = ['facebook_page_url', 'facebook_page_id', 'instagram_username',
        'instagram_account_id', 'meta_app_id', 'webhook_url', 'n8n_credential_name'];

    public function edit() {
        return view('connections', ['details' => IntegrationSetting::find('meta')?->data ?? []]);
    }

    public function update(Request $request) {
        // This form stores account identifiers only. Credentials belong in n8n.
        foreach (['password', 'access_token', 'app_secret', 'api_key'] as $secret) {
            if ($request->filled($secret)) return back()->withErrors(['connection' => 'Enter credentials only in the private n8n credential form. Nothing was saved.']);
        }
        $data = $request->only(self::FIELDS);
        foreach ($data as $key => $value) if (is_string($value)) $data[$key] = trim($value);
        if (is_string($data['instagram_username'] ?? null)) $data['instagram_username'] = ltrim($data['instagram_username'], '@');
        $facebookUrl = function ($attribute, $value, $fail) {
            $url = parse_url($value);
            if (!is_array($url) || ($url['scheme'] ?? '') !== 'https'
                || !in_array(strtolower($url['host'] ?? ''), ['facebook.com', 'www.facebook.com', 'm.facebook.com'], true)
                || isset($url['user']) || isset($url['pass']) || isset($url['port'])
                || preg_match('/(?:token|secret|password|api_key|access_key|code)=/i', $url['query'] ?? '')) {
                $fail('Use a Facebook Page HTTPS link without credentials or secret query parameters.');
            }
        };
        $webhookUrl = function ($attribute, $value, $fail) {
            $url = parse_url($value);
            $host = strtolower($url['host'] ?? '');
            $ip = filter_var($host, FILTER_VALIDATE_IP);
            if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || $host === '' || !str_contains($host, '.')
                || preg_match('/(?:^localhost$|\.(?:localhost|local|test)$)/', $host)
                || ($ip && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))
                || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
                $fail('Use a public HTTPS webhook URL without login details or query parameters.');
            }
        };
        $validator = Validator::make($data, [
            'facebook_page_url' => ['nullable', 'string', 'max:2048', 'url:https', $facebookUrl],
            'facebook_page_id' => ['nullable', 'string', 'regex:/^[0-9]{5,40}$/'],
            'instagram_username' => ['nullable', 'string', 'regex:/^[A-Za-z0-9._]{1,30}$/'],
            'instagram_account_id' => ['nullable', 'string', 'regex:/^[0-9]{5,40}$/'],
            'meta_app_id' => ['nullable', 'string', 'regex:/^[0-9]{5,40}$/'],
            'webhook_url' => ['nullable', 'string', 'max:2048', 'url:https', $webhookUrl],
            'n8n_credential_name' => ['nullable', 'string', 'max:120'],
        ]);
        // Never flash failed values; an accidentally pasted credential must not be echoed.
        if ($validator->fails()) return back()->withErrors($validator);
        IntegrationSetting::updateOrCreate(['key' => 'meta'], ['data' => $validator->validated()]);
        return redirect()->route('connections.edit')->with('notice', 'Connection details saved. Private Facebook/Instagram intake stays pending until Meta authorization and webhook verification are completed.');
    }
}
