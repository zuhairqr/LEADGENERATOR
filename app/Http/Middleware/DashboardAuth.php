<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class DashboardAuth
{
    public function handle(Request $request, Closure $next)
    {
        $accountId=$request->session()->get('team_account_id');
        $account=$accountId?\App\Models\OperatorAccount::find($accountId):null;
        $hash = $accountId ? ($account && $account->active ? $account->password_hash : '') : (string) config('leadgen.admin_password_hash');
        $expected = hash('sha256', $hash);
        if ($hash === '' || !hash_equals($expected, (string) $request->session()->get('operator', ''))) {
            return redirect()->route('login');
        }
        $role=$account?->role??'owner';$request->attributes->set('operator_role',$role);$request->attributes->set('operator_name',$account?->name??'Owner');
        app(\App\Services\OperatorAccess::class)->authorize($request,$role);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        return $response;
    }
}
