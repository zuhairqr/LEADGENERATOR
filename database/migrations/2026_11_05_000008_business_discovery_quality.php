<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('business_records',fn(Blueprint $t)=>$t->json('discovery_evidence')->nullable());
  Schema::table('collection_runs',fn(Blueprint $t)=>$t->json('quality_summary')->nullable());
 }
 public function down():void {
  Schema::table('business_records',fn(Blueprint $t)=>$t->dropColumn('discovery_evidence'));
  Schema::table('collection_runs',fn(Blueprint $t)=>$t->dropColumn('quality_summary'));
 }
};
