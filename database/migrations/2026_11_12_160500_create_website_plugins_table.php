<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_plugins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('plugin_id', 80);
            $table->text('settings');
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->unique(['site_id', 'plugin_id']);
            $table->index(['site_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_plugins');
    }
};
