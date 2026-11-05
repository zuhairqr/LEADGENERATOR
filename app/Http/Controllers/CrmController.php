<?php
namespace App\Http\Controllers;
use App\Models\{Lead,LeadNote,LeadTask,OutreachEvent,OutboundMessage,InboxEvent,CollectionRun};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class CrmController {
 public function note(Request $r,Lead $lead){$d=$r->validate(['body'=>'required|string|max:8000']);LeadNote::create(['lead_id'=>$lead->id,'body'=>$d['body'],'author'=>$r->attributes->get('operator_name','Owner')]);OutreachEvent::create(['lead_id'=>$lead->id,'event'=>'note_added']);return back()->with('notice','Note saved.');}
 public function task(Request $request, Lead $lead)
 {
     $data = $request->validate(['title' => 'required|string|max:200', 'due_at' => 'required|date']);
     $due = \Carbon\Carbon::parse($data['due_at'], 'Asia/Karachi')->utc();
     DB::transaction(function () use ($request, $lead, $data, $due) {
         $lead = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
         $lead->tasks()->create([
             'title' => $data['title'],
             'due_at' => $due,
             'author' => $request->attributes->get('operator_name', 'Owner'),
         ]);
         $lead->refreshNextAction();
     }, 3);

     return back()->with('notice', 'Task scheduled.')->withFragment('lead-notes');
 }

 public function complete(LeadTask $task)
 {
     DB::transaction(function () use ($task) {
         $lead = Lead::whereKey($task->lead_id)->lockForUpdate()->firstOrFail();
         $lead->tasks()->whereKey($task->id)->whereNull('completed_at')->update(['completed_at' => now()]);
         $lead->refreshNextAction();
     }, 3);

     return back()->with('notice', 'Task completed.')->withFragment('lead-notes');
 }
 public function reports(){return view('reports',['leadStates'=>Lead::prospects()->selectRaw('status,COUNT(*) AS count')->groupBy('status')->pluck('count','status'),'sourceStats'=>Lead::prospects()->selectRaw('source, COUNT(*) AS total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS won', ['won'])->groupBy('source')->get(),'messageStates'=>OutboundMessage::sales()->selectRaw('status,COUNT(*) AS count')->groupBy('status')->pluck('count','status'),'replyIntents'=>InboxEvent::whereIn('lead_id',Lead::prospects()->select('id'))->selectRaw('intent,COUNT(*) AS count')->groupBy('intent')->pluck('count','intent'),'dueTasks'=>LeadTask::whereNull('completed_at')->orderBy('due_at')->limit(50)->get(),'collectionFailures'=>CollectionRun::where('status','failed')->latest()->limit(10)->get(),'total'=>Lead::prospects()->count(),'won'=>Lead::prospects()->where('status','won')->count(),'replied'=>Lead::prospects()->whereNotNull('replied_at')->count()]);}
 public function export(){return response()->streamDownload(function(){ $h=fopen('php://output','wb');fputcsv($h,['id','kind','name','website','source','status','contact_email','match_score','next_action_at'],',','"','');Lead::prospects()->with('business')->orderBy('id')->chunkById(200,function($rows)use($h){foreach($rows as $l){$values=[$l->id,$l->kind,$l->title,$l->business?->website??$l->url,$l->source,$l->status,$l->contact_email,$l->match_score,$l->next_action_at];$values=array_map(fn($v)=>preg_match('/^[\s]*[=+@-]/u',(string)$v)?"'".$v:$v,$values);fputcsv($h,$values,',','"','');}});fclose($h);},'lead-export.csv',['Content-Type'=>'text/csv']);}
}
