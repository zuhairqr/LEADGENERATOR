<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('business_records',function(Blueprint $t){$t->id();$t->char('identity_key',64)->unique();$t->string('name',200);$t->string('domain',253)->nullable()->index();$t->text('website')->nullable();$t->string('niche',120)->nullable();$t->string('location',200)->nullable();$t->string('phone',80)->nullable();$t->json('contacts')->nullable();$t->timestamps();});
  Schema::table('leads',function(Blueprint $t){$t->string('kind',20)->default('request')->index();$t->foreignId('business_record_id')->nullable()->constrained();$t->string('reply_intent',30)->nullable();$t->timestamp('next_action_at')->nullable()->index();});
  Schema::create('source_provenances',function(Blueprint $t){$t->id();$t->foreignId('lead_id')->constrained();$t->string('source',80);$t->string('external_id',190);$t->text('url');$t->text('attribution')->nullable();$t->timestamps();$t->unique(['source','external_id']);});
  Schema::create('website_audits',function(Blueprint $t){$t->id();$t->foreignId('lead_id')->constrained();$t->text('requested_url');$t->text('final_url')->nullable();$t->string('status',30)->default('pending')->index();$t->unsignedSmallInteger('http_status')->nullable();$t->unsignedInteger('elapsed_ms')->nullable();$t->unsignedTinyInteger('score')->nullable();$t->json('evidence')->nullable();$t->json('findings')->nullable();$t->string('error_code',80)->nullable();$t->timestamps();});
  Schema::create('lead_notes',function(Blueprint $t){$t->id();$t->foreignId('lead_id')->constrained();$t->text('body');$t->string('author',150);$t->timestamps();});
  Schema::create('lead_tasks',function(Blueprint $t){$t->id();$t->foreignId('lead_id')->constrained();$t->string('title',200);$t->timestamp('due_at')->index();$t->timestamp('completed_at')->nullable();$t->string('author',150);$t->timestamps();});
  Schema::create('inbox_events',function(Blueprint $t){$t->id();$t->char('event_key',64)->unique();$t->foreignId('lead_id')->nullable()->constrained();$t->uuid('outbound_message_id')->nullable();$t->string('provider',30);$t->string('provider_message_id',255);$t->string('sender',254)->nullable();$t->string('subject',255)->nullable();$t->string('intent',30)->default('reply');$t->string('classification_method',40)->default('rules');$t->text('excerpt')->nullable();$t->timestamps();});
  Schema::create('operator_accounts',function(Blueprint $t){$t->id();$t->string('name',150);$t->string('email',254)->unique();$t->string('password_hash');$t->string('role',30)->default('reviewer');$t->boolean('active')->default(true);$t->timestamps();});
  Schema::create('connector_configs',function(Blueprint $t){$t->uuid('id')->primary();$t->string('name',150);$t->string('platform',80);$t->string('purpose',30);$t->text('endpoint');$t->json('settings');$t->boolean('enabled')->default(false);$t->timestamps();});
  Schema::create('connector_runs',function(Blueprint $t){$t->id();$t->uuid('connector_id');$t->string('status',30);$t->unsignedInteger('imported')->default(0);$t->unsignedInteger('duplicates')->default(0);$t->unsignedInteger('rejected')->default(0);$t->string('error_code',80)->nullable();$t->timestamp('retry_after')->nullable();$t->timestamps();$t->index(['connector_id','created_at']);});
  Schema::create('channel_messages',function(Blueprint $t){$t->uuid('id')->primary();$t->foreignId('lead_id')->constrained();$t->uuid('connector_id');$t->string('recipient',254);$t->text('body');$t->string('status',30)->default('draft')->index();$t->char('approval_hash',64)->nullable();$t->timestamp('approved_at')->nullable();$t->timestamp('sent_at')->nullable();$t->string('receipt',255)->nullable();$t->string('error_code',80)->nullable();$t->timestamps();});
 }
 public function down():void {
  foreach(['channel_messages','connector_runs','connector_configs','operator_accounts','inbox_events','lead_tasks','lead_notes','website_audits','source_provenances'] as $name)Schema::dropIfExists($name);
  Schema::table('leads',function(Blueprint $t){$t->dropConstrainedForeignId('business_record_id');$t->dropColumn(['kind','reply_intent','next_action_at']);});Schema::dropIfExists('business_records');
 }
};
