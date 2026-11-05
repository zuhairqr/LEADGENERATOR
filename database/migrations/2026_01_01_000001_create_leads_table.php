<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('source', 80)->index();
            $table->string('source_id', 190);
            $table->char('url_hash', 64)->unique();
            $table->text('url');
            $table->string('title');
            $table->text('description');
            $table->decimal('budget_min', 12, 2)->nullable();
            $table->decimal('budget_max', 12, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedTinyInteger('match_score')->default(0)->index();
            $table->json('matched_services');
            $table->text('match_reason');
            $table->unsignedTinyInteger('ai_score')->nullable();
            $table->text('ai_reason')->nullable();
            $table->text('proposal')->nullable();
            $table->string('ai_model')->nullable();
            $table->timestamp('drafted_at')->nullable();
            $table->string('status', 20)->default('new')->index();
            $table->timestamps();
            $table->unique(['source', 'source_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('leads'); }
};
