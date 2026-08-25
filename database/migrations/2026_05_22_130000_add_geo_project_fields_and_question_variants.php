<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_libraries', function (Blueprint $table): void {
            if (! Schema::hasColumn('keyword_libraries', 'company_name')) {
                $table->string('company_name', 200)->nullable()->after('description');
            }
            if (! Schema::hasColumn('keyword_libraries', 'domain_keyword')) {
                $table->string('domain_keyword', 200)->nullable()->after('company_name');
            }
            if (! Schema::hasColumn('keyword_libraries', 'industry')) {
                $table->string('industry', 100)->nullable()->after('domain_keyword');
            }
            if (! Schema::hasColumn('keyword_libraries', 'brand_description')) {
                $table->text('brand_description')->nullable()->after('industry');
            }
            if (! Schema::hasColumn('keyword_libraries', 'status')) {
                $table->string('status', 20)->default('active')->after('brand_description');
            }
        });

        if (! Schema::hasTable('keyword_question_variants')) {
            Schema::create('keyword_question_variants', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('keyword_id')->constrained('keywords')->cascadeOnDelete();
                $table->text('question');
                $table->timestamps();
                $table->unique(['keyword_id', 'question']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_question_variants');

        Schema::table('keyword_libraries', function (Blueprint $table): void {
            foreach (['status', 'brand_description', 'industry', 'domain_keyword', 'company_name'] as $column) {
                if (Schema::hasColumn('keyword_libraries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
