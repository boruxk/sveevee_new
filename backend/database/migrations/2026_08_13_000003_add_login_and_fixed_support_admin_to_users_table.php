<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SUPPORT_ADMIN_EMAIL = 'support@sveevee.local';

    private const SUPPORT_ADMIN_LOGIN = 'sffSrgsrgrsgsG';

    private const SUPPORT_ADMIN_PASSWORD_HASH = '$2y$12$0EwahlZ6aaOTuMk0ajC9duANhv7cj5HOlJINXPviuabUnZeUg6T.2';

    public function up(): void
    {
        if (! Schema::hasColumn('users', 'login')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('login')->nullable()->unique()->after('id');
            });
        }

        $now = now();
        $admin = DB::table('users')
            ->where('login', self::SUPPORT_ADMIN_LOGIN)
            ->orWhere('email', self::SUPPORT_ADMIN_EMAIL)
            ->first();

        $values = [
            'login' => self::SUPPORT_ADMIN_LOGIN,
            'name' => 'sveevee Support',
            'given_name' => 'sveevee',
            'family_name' => 'Support',
            'email' => self::SUPPORT_ADMIN_EMAIL,
            'email_verified_at' => $now,
            'password' => self::SUPPORT_ADMIN_PASSWORD_HASH,
            'locale' => 'he',
            'role' => 'admin',
            'banned_at' => null,
            'banned_reason' => null,
            'updated_at' => $now,
        ];

        if ($admin) {
            DB::table('users')->where('id', $admin->id)->update($values);
        } else {
            DB::table('users')->insert([
                ...$values,
                'created_at' => $now,
            ]);
        }

        $adminId = DB::table('users')->where('login', self::SUPPORT_ADMIN_LOGIN)->value('id');

        if ($adminId && Schema::hasTable('user_profiles')) {
            $profile = DB::table('user_profiles')->where('user_id', $adminId)->first();
            $profileValues = [
                'city' => 'Jerusalem',
                'neighborhood' => 'Ramot',
                'updated_at' => $now,
            ];

            if ($profile) {
                DB::table('user_profiles')->where('id', $profile->id)->update($profileValues);
            } else {
                DB::table('user_profiles')->insert([
                    ...$profileValues,
                    'user_id' => $adminId,
                    'created_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'login')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_login_unique');
            $table->dropColumn('login');
        });
    }
};
