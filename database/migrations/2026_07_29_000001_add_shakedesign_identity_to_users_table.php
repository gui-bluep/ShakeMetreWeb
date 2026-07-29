<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The ZUSR_Users zkp of the ShakeDesign account this user came from. Nullable
            // because accounts created through Breeze's own registration have no ShakeDesign
            // counterpart; unique so one FileMaker account cannot map to two Laravel users.
            $table->uuid('shakedesign_user_id')->nullable()->unique()->after('email');

            $table->enum('role', array_column(UserRole::cases(), 'value'))
                ->default(UserRole::ReadOnly->value)
                ->after('shakedesign_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['shakedesign_user_id']);
            $table->dropColumn(['shakedesign_user_id', 'role']);
        });
    }
};
