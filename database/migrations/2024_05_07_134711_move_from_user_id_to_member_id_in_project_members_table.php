<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('project_members', 'member_id')) {
            Schema::table('project_members', function (Blueprint $table): void {
                $table->foreignUuid('member_id')
                    ->nullable()
                    ->constrained('organization_user')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();
            });
        }
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('
                update project_members
                join projects on projects.id = project_members.project_id
                join organization_user on organization_user.organization_id = projects.organization_id
                    and organization_user.user_id = project_members.user_id
                set project_members.member_id = organization_user.id
            ');
        } else {
            DB::statement('
                update project_members
                set member_id = organization_user.id
                from projects
                join organization_user on organization_user.organization_id = projects.organization_id
                where projects.id = project_members.project_id and project_members.user_id = organization_user.user_id
            ');
        }
        Schema::table('project_members', function (Blueprint $table): void {
            $table->uuid('member_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_members', function (Blueprint $table): void {
            $table->dropForeign(['member_id']);
            $table->dropColumn('member_id');
        });
    }
};
