<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brand_diagnosis_lookup_jobs')) {
            return;
        }

        Schema::create('brand_diagnosis_lookup_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('lookup_id', 48)->unique();
            $table->string('brand_word', 120);
            $table->string('canonical_key', 160);
            $table->json('includes');
            $table->string('status', 20)->default('pending')->index();
            $table->json('result')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->unsignedSmallInteger('error_status')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['canonical_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_diagnosis_lookup_jobs');
    }
};
