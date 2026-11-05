<?php
namespace App\Http\Controllers;
use App\Models\OperatorAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
class TeamController {
 public function index(){return view('team',['accounts'=>OperatorAccount::orderBy('name')->get()]);}
 public function save(Request $r,?OperatorAccount $account=null){
  $v=Validator::make($r->all(),['name'=>'required|string|max:150','email'=>['required','email','max:254',Rule::unique('operator_accounts','email')->ignore($account?->id)],'role'=>['required',Rule::in(['admin','reviewer','viewer'])],'password'=>[$account?->exists?'nullable':'required','string','min:12','max:512','confirmed']]);
  if($v->fails())return back()->withErrors($v);$d=$v->validated();$d['email']=strtolower($d['email']);
  if(strcasecmp($d['email'],config('leadgen.admin_email'))===0)return back()->withErrors(['email'=>'This address belongs to the environment owner.']);
  $account??=new OperatorAccount;$account->name=$d['name'];$account->email=$d['email'];$account->role=$d['role'];$account->active=$r->boolean('active');if(!empty($d['password']))$account->password_hash=password_hash($d['password'],PASSWORD_DEFAULT);$account->save();
  return back()->with('notice','Team access saved. Password changes and disabling an account revoke its existing sessions.');
 }
}
