<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_submissions', function (Blueprint $table): void {
            if (! Schema::hasColumn('media_submissions', 'cancel_reason')) {
                $table->text('cancel_reason')->nullable()->after('last_error_message');
            }
            if (! Schema::hasColumn('media_submissions', 'appeal_content')) {
                $table->text('appeal_content')->nullable()->after('cancel_reason');
            }
            if (! Schema::hasColumn('media_submissions', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('last_synced_at');
            }
            if (! Schema::hasColumn('media_submissions', 'appealed_at')) {
                $table->timestamp('appealed_at')->nullable()->after('cancelled_at');
            }
            if (! Schema::hasColumn('media_submissions', 'raw_cancel_response')) {
                $table->json('raw_cancel_response')->nullable()->after('raw_status_response');
            }
            if (! Schema::hasColumn('media_submissions', 'raw_appeal_response')) {
                $table->json('raw_appeal_response')->nullable()->after('raw_cancel_response');
            }
        });
    }

    public function down(): void
    {
        Schema::table('media_submissions', function (Blueprint $table): void {
            foreach ([
                'raw_appeal_response',
                'raw_cancel_response',
                'appealed_at',
                'cancelled_at',
                'appeal_content',
                'cancel_reason',
            ] as $column) {
                if (Schema::hasColumn('media_submissions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
