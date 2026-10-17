<?php

use App\Support\AdminPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One switch used to decide what an administrator could do: a boolean that opened
 * every screen at once. That made the smallest job the riskiest one to hand out -
 * a person who only needs to read a customer's history also got the ability to move
 * that customer's money, rewrite the storefront and hand admin access to a friend.
 *
 * This table is the answer to "which administrator is allowed to do which thing",
 * one row per pair, so a grant can be read back, dated and signed by whoever made
 * it. Revocation removes the row; the history of both acts lives in
 * admin_activity_logs, which this table deliberately does not try to duplicate.
 *
 * Missing rows, not false ones: an account named here for a permission it was never
 * given would be a grant nobody signed. So the read side treats an absent row as
 * "not allowed", and the only accounts that hold everything without a row are the
 * Master Admins named in configuration - the owner's own decision, outside the
 * reach of any website screen.
 *
 * Existing administrators keep their reach on purpose. Every admin account already
 * in the database is seeded with the full set, because a deploy that quietly turned
 * the support desk into forty-three people staring at a refusal is not a safety
 * improvement, it is an outage. Each of those rows says who signed it: nobody, and
 * why: the baseline. From the deploy onwards the Master Admin narrows that set, and
 * one click on the permissions screen is the only way anything widens again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('admin_permission_grants')) {
            Schema::create('admin_permission_grants', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('permission', 64);
                $table->unsignedBigInteger('granted_by_user_id')->nullable();
                $table->string('note', 500)->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'permission']);
                $table->index('permission');
            });
        }

        $masterIds = AdminPermissions::masterUserIds();
        $now = now();

        $admins = DB::table('users')->where('is_admin', true)->pluck('id')->all();

        $rows = [];

        foreach ($admins as $adminId) {
            // A Master Admin holds the whole set implicitly and their grant rows are
            // refused by the screen, so writing them would create history nobody can
            // act on.
            if (in_array((int) $adminId, $masterIds, true)) {
                continue;
            }

            foreach (AdminPermissions::slugs() as $slug) {
                $rows[] = [
                    'user_id' => (int) $adminId,
                    'permission' => $slug,
                    'granted_by_user_id' => null,
                    'note' => AdminPermissions::baselineNote(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('admin_permission_grants')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        // Dropping this table removes every recorded grant. The site would return to
        // one switch for everyone, and the decision of which administrator holds
        // which permission would be gone - only its audit history would remain. This
        // is why a rollback is not a production recovery strategy here: the rows
        // cannot be rebuilt from anything else in the database.
        Schema::dropIfExists('admin_permission_grants');
    }
};
