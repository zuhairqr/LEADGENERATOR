<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{
  Schema::table('business_records',function(Blueprint $t){$t->char('country_code',2)->nullable();$t->string('geo_status',20)->default('unknown')->index();$t->text('country_evidence')->nullable();});
  Schema::table('channel_messages',function(Blueprint $t){$t->uuid('parent_id')->nullable();$t->unsignedTinyInteger('sequence')->default(0);$t->timestamp('followup_due_at')->nullable();$t->timestamp('permission_at')->nullable();});
 }
 public function down():void{Schema::table('channel_messages',fn(Blueprint $t)=>$t->dropColumn(['parent_id','sequence','followup_due_at','permission_at']));Schema::table('business_records',fn(Blueprint $t)=>$t->dropColumn(['country_code','geo_status','country_evidence']));}
};
