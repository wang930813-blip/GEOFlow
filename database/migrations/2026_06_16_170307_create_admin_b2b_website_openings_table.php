<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_b2b_website_openings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('owner_admin_id')->constrained('admins')->cascadeOnDelete();
            $table->string('website_key', 80);
            $table->timestamps();

            $table->unique(['site_id', 'owner_admin_id', 'website_key'], 'admin_b2b_openings_site_owner_key_unique');
            $table->index(['site_id', 'owner_admin_id'], 'admin_b2b_openings_site_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_b2b_website_openings');
    }
};
