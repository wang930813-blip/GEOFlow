<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('video_generation_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('owner_admin_id')->constrained('admins')->cascadeOnDelete();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('title', 255)->default('');
            $table->string('subject', 500);
            $table->longText('script')->nullable();
            $table->string('terms', 1000)->default('');
            $table->string('negative_terms', 1000)->default('');
            $table->string('video_source', 60)->default('pexels');
            $table->string('video_aspect', 20)->default('9:16');
            $table->unsignedSmallInteger('video_count')->default(1);
            $table->string('cover_image', 1000)->default('');
            $table->string('status', 30)->default('queued')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('api_task_id', 160)->nullable()->index();
            $table->json('request_payload')->nullable();
            $table->json('result_payload')->nullable();
            $table->json('videos')->nullable();
            $table->json('combined_videos')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('quota_ledger_id')->nullable()->constrained('admin_resource_ledger')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'owner_admin_id', 'status']);
            $table->index(['site_id', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_generation_jobs');
    }
};
