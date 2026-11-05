<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('leads', function (Blueprint $t) {
            $t->string('contact_email',254)->nullable(); $t->timestamp('contact_permission_at')->nullable();
            $t->string('ai_state',30)->default('pending')->index(); $t->boolean('ai_qualified')->nullable();
            $t->timestamp('replied_at')->nullable(); $t->timestamp('bounced_at')->nullable(); $t->timestamp('unsubscribed_at')->nullable();
        });
        Schema::create('integration_secrets', function (Blueprint $t) { $t->string('key',40)->primary(); $t->longText('data'); $t->timestamps(); });
        Schema::create('outbound_messages', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignId('lead_id')->constrained(); $t->unsignedTinyInteger('sequence')->default(0);
            $t->uuid('parent_id')->nullable(); $t->string('recipient',254)->nullable();
            $t->string('subject',200); $t->text('body'); $t->string('kind',20)->default('initial');
            $t->string('status',30)->default('draft')->index(); $t->unsignedInteger('revision')->default(1);
            $t->string('from_email',254)->nullable(); $t->string('from_name',150)->nullable(); $t->string('transport',20)->nullable();
            $t->char('approval_hash',64)->nullable(); $t->timestamp('approved_at')->nullable();
            $t->timestamp('sending_at')->nullable(); $t->timestamp('sent_at')->nullable();
            $t->string('message_id',255)->nullable()->index(); $t->string('thread_id',255)->nullable()->index();
            $t->timestamp('followup_due_at')->nullable()->index(); $t->string('error_code',80)->nullable(); $t->timestamps();
            $t->unique(['lead_id','sequence']);
        });
        Schema::create('ai_attempts', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignId('lead_id')->constrained(); $t->string('kind',20);
            $t->string('status',30); $t->string('error_code',80)->nullable(); $t->timestamps(); $t->index('created_at');
        });
        Schema::create('outreach_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('lead_id')->constrained(); $t->uuid('message_id')->nullable();
            $t->string('event',60); $t->json('details')->nullable(); $t->timestamps();
        });
    }
    public function down(): void {
        foreach (['outreach_events','ai_attempts','outbound_messages','integration_secrets'] as $table) Schema::dropIfExists($table);
        Schema::table('leads', fn (Blueprint $t) => $t->dropColumn(['contact_email','contact_permission_at','ai_state','ai_qualified','replied_at','bounced_at','unsubscribed_at']));
    }
};
