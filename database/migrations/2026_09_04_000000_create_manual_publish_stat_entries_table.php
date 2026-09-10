<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_publish_stat_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('owner_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('metric_type', 40)->index();
            $table->unsignedInteger('quantity');
            $table->date('stat_date')->index();
            $table->string('remark', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['site_id', 'stat_date']);
            $table->index(['site_id', 'metric_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_publish_stat_entries');
    }
};
