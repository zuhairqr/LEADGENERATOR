<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{Schema::table('leads',function(Blueprint $t){$t->char('country_code',2)->nullable();$t->timestamp('country_reviewed_at')->nullable();});}
 public function down():void{Schema::table('leads',fn(Blueprint $t)=>$t->dropColumn(['country_code','country_reviewed_at']));}
};
