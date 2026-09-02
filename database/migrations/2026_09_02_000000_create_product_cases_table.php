<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('owner_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('title', 180);
            $table->string('slug', 220)->unique();
            $table->string('company_name', 180)->default('');
            $table->string('logo_url', 500)->default('');
            $table->string('cover_url', 500)->default('');
            $table->string('industry', 120)->default('');
            $table->string('region', 120)->default('');
            $table->string('business_mode', 120)->default('');
            $table->json('module_tags')->nullable();
            $table->string('summary', 1000)->default('');
            $table->longText('content')->nullable();
            $table->string('customer_level', 80)->default('');
            $table->date('started_at')->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->integer('sort_order')->default(0)->index();
            $table->unsignedBigInteger('view_count')->default(0);
            $table->timestamp('published_at')->nullable()->index();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at', 'sort_order']);
            $table->index(['industry', 'region']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_cases');
    }
};
