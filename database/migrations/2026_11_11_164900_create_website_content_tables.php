<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('owner_admin_id')->constrained('admins')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('title', 200);
            $table->string('slug', 180);
            $table->string('summary', 1000)->default('');
            $table->string('cover_image', 1000)->default('');
            $table->longText('content');
            $table->string('meta_title', 255)->default('');
            $table->string('meta_description', 320)->default('');
            $table->string('status', 20)->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['site_id', 'slug']);
            $table->index(['site_id', 'owner_admin_id', 'status', 'sort_order'], 'website_products_tenant_status_idx');
        });

        Schema::create('website_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('owner_admin_id')->constrained('admins')->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('slug', 180);
            $table->longText('content');
            $table->string('meta_title', 255)->default('');
            $table->string('meta_description', 320)->default('');
            $table->string('status', 20)->default('draft');
            $table->boolean('show_in_navigation')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['site_id', 'slug']);
            $table->index(['site_id', 'owner_admin_id', 'status', 'sort_order'], 'website_pages_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_pages');
        Schema::dropIfExists('website_products');
    }
};
