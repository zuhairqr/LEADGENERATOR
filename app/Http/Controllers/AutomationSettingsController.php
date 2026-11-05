<?php
namespace App\Http\Controllers;
use App\Models\{IntegrationSetting,IntegrationSecret,OutboundMessage};
use App\Services\ProspectingProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AutomationSettingsController {
    public function edit(ProspectingProfile $profile) {
        $data=$profile->data(); $smtp=IntegrationSecret::find('smtp')?->data ?? [];
        unset($smtp['password']);
        $skills = $profile->configuredSkills();
        return view('automation',['profile'=>$data,'skillText'=>implode("\n",array_map(fn ($name,$terms)=>$name.': '.implode(', ',$terms),array_keys($skills),array_values($skills))),
            'sources'=>array_filter(config('leadgen.sources'),fn ($s)=>($s['kind'] ?? '')!=='meta'),'mail'=>$profile->sender(),'smtp'=>$smtp,
            'hasSmtpPassword'=>IntegrationSecret::find('smtp')!==null,'bridges'=>$profile->bridges(),'smtpHealth'=>IntegrationSetting::find('smtp_health')?->data ?? []]);
    }
    public function update(Request $r) {
        $r->merge(['enabled_sources'=>array_values(array_filter((array)$r->input('enabled_sources',[]),fn ($x)=>is_string($x) && $x!==''))]);
        $v=Validator::make($r->all(),[
            'skills_text'=>['required','string','max:8000'],'excluded_text'=>['nullable','string','max:1000'],'locations_text'=>['nullable','string','max:800'],
            'business_name'=>['nullable','string','max:150'],'verified_facts'=>['nullable','string','max:6000'],'signature'=>['nullable','string','max:1000'],
            'language'=>['required','string','max:80'],'ai_credential_name'=>['nullable','string','max:150'],'minimum_score'=>['required','integer','between:0,100'],'max_age_days'=>['required','integer','between:1,90'],
            'daily_ai_limit'=>['required','integer','between:1,25'],'followup_days'=>['required','integer','between:1,30'],'max_followups'=>['required','integer','between:0,3'],
            'enabled_sources'=>['present','array'],'enabled_sources.*'=>[Rule::in(array_keys(config('leadgen.sources')))],
        ]);
        if ($v->fails()) return back()->withErrors($v);
        $d=$v->validated(); $skills=[];
        foreach (preg_split('/\R/u',$d['skills_text']) as $line) {
            if (trim($line)==='') continue; $parts=explode(':',$line,2); $name=trim($parts[0]);
            $terms=array_values(array_unique(array_filter(array_map('trim',explode(',',$parts[1] ?? $name)))));
            if (mb_strlen($name)>80 || !$terms || count($terms)>10 || count($skills)>=20 || array_filter($terms,fn ($x)=>mb_strlen($x)>80)) return back()->withErrors(['skills_text'=>'Use up to 20 skills, each with up to 10 keywords of 80 characters or fewer.']);
            $skills[$name]=$terms;
        }
        if (!$skills) return back()->withErrors(['skills_text'=>'Add at least one skill.']);
        $list=fn ($text)=>array_slice(array_values(array_unique(array_filter(array_map('trim',preg_split('/[,\r\n]+/u',$text ?? ''))))),0,20);
        $d['skills']=$skills; $d['excluded_keywords']=$list($d['excluded_text']); $d['locations']=$list($d['locations_text']);
        unset($d['skills_text'],$d['excluded_text'],$d['locations_text']);
        foreach (['minimum_score','max_age_days','daily_ai_limit','followup_days','max_followups'] as $key) $d[$key]=(int)$d[$key];
        foreach (['auto_draft','profile_verified','ai_consent'] as $key) $d[$key]=$r->boolean($key);
        $d['ai_model']=$r->validate(['ai_model'=>['required','string','max:100','regex:/^[A-Za-z0-9_.:\/-]+$/']])['ai_model'];
        if ($d['auto_draft'] && (!$d['profile_verified'] || !$d['ai_consent'] || trim($d['business_name'] ?? '')==='' || trim($d['verified_facts'] ?? '')==='')) return back()->withErrors(['profile'=>'Enter and confirm your actual business facts and AI consent before enabling automatic drafts.']);
        \Illuminate\Support\Facades\DB::transaction(function () use ($d) {
            $previous = IntegrationSetting::lockForUpdate()->find('pipeline')?->data ?? [];
            $d += array_intersect_key($previous, array_flip(['automatic_outreach_paused', 'project_discovery_profile']));
            IntegrationSetting::updateOrCreate(['key'=>'pipeline'],['data'=>$d]);
        });
        \App\Models\Lead::whereIn('status',['new','shortlisted'])->where('ai_state','pending')->chunkById(100,function ($leads) {
            foreach ($leads as $lead) { $lead->fill(app(\App\Services\LeadMatcher::class)->score($lead->title,$lead->description));$lead->save(); }
        });
        return redirect()->route('automation.edit')->with('notice','Skills and automation settings saved. Search changes apply on the next permitted source run. Every email and follow-up still needs your approval.');
    }
    public function mail(Request $r) {
        $v=Validator::make($r->all(),['provider'=>['required',Rule::in(['smtp','gmail'])],'from_email'=>['required','email','max:254'],'from_name'=>['required','string','max:150','not_regex:/[\r\n]/'],
            'smtp_host'=>['nullable','string','max:253','regex:/^[A-Za-z0-9.-]+$/'],'smtp_port'=>['nullable','integer',Rule::in([465,587])],
            'smtp_username'=>['nullable','string','max:254'],'smtp_password'=>['nullable','string','max:1024'],'smtp_security'=>['nullable',Rule::in(['smtps','starttls'])],
            'gmail_credential_name'=>['nullable','string','max:150'],'imap_credential_name'=>['nullable','string','max:150']]);
        if ($v->fails()) return back()->withErrors($v);
        $d=$v->validated(); $existing=IntegrationSecret::find('smtp')?->data ?? [];
        if ($d['provider']==='smtp') {
            if (empty($d['smtp_host']) || empty($d['smtp_port']) || empty($d['smtp_username']) || (empty($d['smtp_password']) && empty($existing['password']))) return back()->withErrors(['mail'=>'Enter your SMTP host, port, username and password. A blank password preserves an existing one.']);
            IntegrationSecret::updateOrCreate(['key'=>'smtp'],['data'=>['host'=>$d['smtp_host'],'port'=>(int)$d['smtp_port'],'username'=>$d['smtp_username'],
                'password'=>!empty($d['smtp_password'])?$d['smtp_password']:$existing['password'],'security'=>$d['smtp_security'] ?? 'starttls']]);
        }
        $previous=IntegrationSetting::find('mail')?->data??[];
        IntegrationSetting::updateOrCreate(['key'=>'mail'],['data'=>['provider'=>$d['provider'],'from_email'=>strtolower($d['from_email']),'from_name'=>$d['from_name'],'sender_verified'=>$r->boolean('sender_verified'),
            'gmail_credential_name'=>$d['gmail_credential_name'] ?? '', 'imap_credential_name'=>$d['imap_credential_name'] ?? '']]);
        IntegrationSetting::whereKey('smtp_health')->delete();
        if(($previous['from_email']??'')!==strtolower($d['from_email'])||($previous['gmail_credential_name']??'')!==($d['gmail_credential_name']??''))IntegrationSetting::whereIn('key',['mailbox_health','mailbox_sync'])->delete();
        OutboundMessage::where('status','approved')->update(['status'=>'draft','approval_hash'=>null,'approved_at'=>null,'error_code'=>'SENDER_CHANGED_REAPPROVE']);
        return redirect()->route('automation.edit')->with('notice','Sender settings saved privately. Previously queued approvals require review again after a sender change.');
    }
    public function testMail(\App\Services\SmtpConnectionCheck $check) {
        set_time_limit(80);$result=$check->check();
        if ($result['authenticated'] && $result['tls'] && $result['sender_matches_authenticated_account']) return back()->with('notice','Saved SMTP connection authenticated over TLS. No email was sent.');
        return back()->withErrors(['mail'=>'SMTP check failed: '.($result['error_code'] ?? 'SENDER_DOES_NOT_MATCH_AUTHENTICATED_ACCOUNT').'. No email was sent.']);
    }
}
