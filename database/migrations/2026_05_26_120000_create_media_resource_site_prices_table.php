<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media_resource_site_prices')) {
            Schema::create('media_resource_site_prices', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
                $table->foreignId('media_resource_id')->constrained('media_resources')->cascadeOnDelete();
                $table->decimal('sale_price', 12, 2)->default(0);
                $table->timestamps();

                $table->unique(['site_id', 'media_resource_id'], 'media_resource_site_prices_unique');
                $table->index('media_resource_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_resource_site_prices');
    }
};
