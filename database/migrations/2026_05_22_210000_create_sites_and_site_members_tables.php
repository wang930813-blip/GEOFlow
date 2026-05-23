<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites')) {
            Schema::create('sites', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('owner_admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->string('name', 120);
                $table->string('domain', 255)->default('');
                $table->string('status', 20)->default('active');
                $table->json('settings')->nullable();
                $table->timestamps();

                $table->index(['owner_admin_id', 'status']);
                $table->index('domain');
            });
        }

        if (! Schema::hasTable('site_members')) {
            Schema::create('site_members', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
                $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
                $table->string('role', 20)->default('admin');
                $table->timestamps();

                $table->unique(['site_id', 'admin_id']);
                $table->index(['admin_id', 'role']);
            });
        }

        $this->createDefaultSitesForExistingAdmins();
    }

    public function down(): void
    {
        Schema::dropIfExists('site_members');
        Schema::dropIfExists('sites');
    }

    private function createDefaultSitesForExistingAdmins(): void
    {
        if (! Schema::hasTable('admins') || ! Schema::hasTable('sites') || ! Schema::hasTable('site_members')) {
            return;
        }

        DB::table('admins')
            ->orderBy('id')
            ->select(['id', 'username', 'display_name'])
            ->chunk(100, function ($admins): void {
                foreach ($admins as $admin) {
                    $existingSiteId = DB::table('site_members')
                        ->where('admin_id', $admin->id)
                        ->value('site_id');

                    if ($existingSiteId !== null) {
                        continue;
                    }

                    $displayName = trim((string) ($admin->display_name ?? ''));
                    $username = trim((string) ($admin->username ?? ''));
                    $baseName = $displayName !== '' ? $displayName : ($username !== '' ? $username : '管理员');
                    $siteId = DB::table('sites')->insertGetId([
                        'owner_admin_id' => $admin->id,
                        'name' => $baseName.' 的默认站点',
                        'domain' => '',
                        'status' => 'active',
                        'settings' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('site_members')->insert([
                        'site_id' => $siteId,
                        'admin_id' => $admin->id,
                        'role' => 'owner',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }
};
