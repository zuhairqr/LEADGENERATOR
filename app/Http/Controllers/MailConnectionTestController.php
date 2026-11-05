<?php
namespace App\Http\Controllers;
use App\Services\MailConnectionTest;
use App\Models\{Lead,OutboundMessage};
use Illuminate\Http\Request;
class MailConnectionTestController {
 public function index(MailConnectionTest $tests){$state=$tests->state();return view('mail-test',['testSender'=>app(\App\Services\ProspectingProfile::class)->sender()['from_email']??'Not configured','testState'=>$state,'testLead'=>isset($state['lead_id'])?Lead::find($state['lead_id']):null,'testMessages'=>isset($state['lead_id'])?OutboundMessage::where('lead_id',$state['lead_id'])->orderBy('sequence')->get():collect()]);}
 public function prepare(Request $r,MailConnectionTest $tests){$d=$r->validate(['recipient'=>'required|email|max:254','ownership'=>'accepted']);try{$tests->prepare($d['recipient']);return redirect()->route('mail-test.index')->with('notice','Fixed technical test draft prepared. Review and approve the exact email in the queue before sending.');}catch(\Throwable $e){return back()->withErrors(['test'=>preg_match('/^[A-Z_]{3,80}$/',$e->getMessage())?$e->getMessage():'TEST_PREPARATION_FAILED']);}}
}
