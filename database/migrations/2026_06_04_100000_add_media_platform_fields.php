<?php

use App\Support\MediaDistribution\MediaPlatform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media_api_settings')) {
            Schema::table('media_api_settings', function (Blueprint $table): void {
                if (! Schema::hasColumn('media_api_settings', 'platform_id')) {
                    $table->unsignedTinyInteger('platform_id')->default(MediaPlatform::CEYING_MEDIA_1)->after('id');
                }
                if (! Schema::hasColumn('media_api_settings', 'app_id')) {
                    $table->string('app_id', 120)->default('')->after('api_key_ciphertext');
                }
                if (! Schema::hasColumn('media_api_settings', 'api_secret_ciphertext')) {
                    $table->text('api_secret_ciphertext')->nullable()->after('app_id');
                }
            });

            DB::table('media_api_settings')
                ->whereNull('platform_id')
                ->orWhere('platform_id', 0)
                ->update(['platform_id' => MediaPlatform::CEYING_MEDIA_1]);

            if (! $this->indexExists('media_api_settings', 'media_api_settings_platform_index')) {
                Schema::table('media_api_settings', function (Blueprint $table): void {
                    $table->index('platform_id', 'media_api_settings_platform_index');
                });
            }
        }

        if (Schema::hasTable('media_resources')) {
            Schema::table('media_resources', function (Blueprint $table): void {
                if (! Schema::hasColumn('media_resources', 'platform_id')) {
                    $table->unsignedTinyInteger('platform_id')->default(MediaPlatform::CEYING_MEDIA_1)->after('id');
                }
            });

            DB::table('media_resources')
                ->whereNull('platform_id')
                ->orWhere('platform_id', 0)
                ->update(['platform_id' => MediaPlatform::CEYING_MEDIA_1]);

            if ($this->indexExists('media_resources', 'media_resources_source_external_unique')) {
                Schema::table('media_resources', function (Blueprint $table): void {
                    $table->dropUnique('media_resources_source_external_unique');
                });
            }
            if (! $this->indexExists('media_resources', 'media_resources_platform_source_external_unique')) {
                Schema::table('media_resources', function (Blueprint $table): void {
                    $table->unique(['platform_id', 'source_type', 'external_resource_id'], 'media_resources_platform_source_external_unique');
                });
            }
            if (! $this->indexExists('media_resources', 'media_resources_platform_source_status_index')) {
                Schema::table('media_resources', function (Blueprint $table): void {
                    $table->index(['platform_id', 'source_type', 'status'], 'media_resources_platform_source_status_index');
                });
            }
        }

        if (Schema::hasTable('media_submissions')) {
            Schema::table('media_submissions', function (Blueprint $table): void {
                if (! Schema::hasColumn('media_submissions', 'platform_id')) {
                    $table->unsignedTinyInteger('platform_id')->default(MediaPlatform::CEYING_MEDIA_1)->after('media_resource_id');
                }
                if (! Schema::hasColumn('media_submissions', 'agent_order_sn')) {
                    $table->string('agent_order_sn', 120)->default('')->after('external_order_nid')->index();
                }
                if (! Schema::hasColumn('media_submissions', 'preview_token')) {
                    $table->string('preview_token', 80)->default('')->after('agent_order_sn')->index();
                }
            });

            DB::table('media_submissions')
                ->whereNull('platform_id')
                ->orWhere('platform_id', 0)
                ->update(['platform_id' => MediaPlatform::CEYING_MEDIA_1]);

            DB::table('media_submissions')
                ->select(['id', 'external_order_nid'])
                ->where('agent_order_sn', '')
                ->where('external_order_nid', '<>', '')
                ->orderBy('id')
                ->chunkById(500, function ($rows): void {
                    foreach ($rows as $row) {
                        DB::table('media_submissions')
                            ->where('id', (int) $row->id)
                            ->update(['agent_order_sn' => (string) $row->external_order_nid]);
                    }
                });

            if (! $this->indexExists('media_submissions', 'media_submissions_platform_status_index')) {
                Schema::table('media_submissions', function (Blueprint $table): void {
                    $table->index(['platform_id', 'status'], 'media_submissions_platform_status_index');
                });
            }
        }

        if (Schema::hasTable('media_resource_sync_runs')) {
            Schema::table('media_resource_sync_runs', function (Blueprint $table): void {
                if (! Schema::hasColumn('media_resource_sync_runs', 'platform_id')) {
                    $table->unsignedTinyInteger('platform_id')->default(MediaPlatform::CEYING_MEDIA_1)->after('id');
                }
            });

            DB::table('media_resource_sync_runs')
                ->whereNull('platform_id')
                ->orWhere('platform_id', 0)
                ->update(['platform_id' => MediaPlatform::CEYING_MEDIA_1]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('media_resource_sync_runs') && Schema::hasColumn('media_resource_sync_runs', 'platform_id')) {
            Schema::table('media_resource_sync_runs', function (Blueprint $table): void {
                $table->dropColumn('platform_id');
            });
        }

        if (Schema::hasTable('media_submissions')) {
            Schema::table('media_submissions', function (Blueprint $table): void {
                if ($this->indexExists('media_submissions', 'media_submissions_platform_status_index')) {
                    $table->dropIndex('media_submissions_platform_status_index');
                }
            });
            Schema::table('media_submissions', function (Blueprint $table): void {
                foreach (['preview_token', 'agent_order_sn', 'platform_id'] as $column) {
                    if (Schema::hasColumn('media_submissions', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('media_resources')) {
            Schema::table('media_resources', function (Blueprint $table): void {
                if ($this->indexExists('media_resources', 'media_resources_platform_source_status_index')) {
                    $table->dropIndex('media_resources_platform_source_status_index');
                }
                if ($this->indexExists('media_resources', 'media_resources_platform_source_external_unique')) {
                    $table->dropUnique('media_resources_platform_source_external_unique');
                }
            });
            if (! $this->indexExists('media_resources', 'media_resources_source_external_unique')) {
                Schema::table('media_resources', function (Blueprint $table): void {
                    $table->unique(['source_type', 'external_resource_id'], 'media_resources_source_external_unique');
                });
            }
            Schema::table('media_resources', function (Blueprint $table): void {
                if (Schema::hasColumn('media_resources', 'platform_id')) {
                    $table->dropColumn('platform_id');
                }
            });
        }

        if (Schema::hasTable('media_api_settings')) {
            Schema::table('media_api_settings', function (Blueprint $table): void {
                if ($this->indexExists('media_api_settings', 'media_api_settings_platform_index')) {
                    $table->dropIndex('media_api_settings_platform_index');
                }
            });
            Schema::table('media_api_settings', function (Blueprint $table): void {
                foreach (['api_secret_ciphertext', 'app_id', 'platform_id'] as $column) {
                    if (Schema::hasColumn('media_api_settings', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return Schema::hasIndex($table, $index);
    }
};
