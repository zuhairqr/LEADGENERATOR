<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('integration_settings', function (Blueprint $table) {
            $table->string('key', 40)->primary();
            $table->json('data');
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('integration_settings'); }
};
