<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('collection_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source', 80)->index();
            $table->string('status', 40)->default('fetching');
            $table->unsignedInteger('fetched')->default(0);
            $table->unsignedInteger('matched')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('rejected')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['source', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('collection_runs'); }
};
