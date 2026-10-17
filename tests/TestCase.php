<?php

namespace Tests;

use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Make an existing account an administrator and give it the baseline permission
     * set, which is what the grant migration did for every admin account already in
     * the database.
     *
     * A test that builds an admin with a factory gets this from the factory's own
     * hook; this is for the accounts that start life as a customer and are promoted
     * mid-test. Pass $permissions to hand it a narrower desk instead, which is how a
     * refusal is tested.
     *
     * @param  list<string>|null  $permissions
     */
    protected function promoteAsAdministrator(User $admin, ?array $permissions = null): User
    {
        $admin->forceFill(['is_admin' => true])->save();

        if ($admin->isMasterAdmin()) {
            return $admin;
        }

        $now = now();

        $admin->permissionGrants()->insertOrIgnore(collect($permissions ?? AdminPermissions::slugs())
            ->map(fn (string $slug): array => [
                'user_id' => (int) $admin->id,
                'permission' => $slug,
                'granted_by_user_id' => null,
                'note' => AdminPermissions::baselineNote(),
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all());

        return $admin;
    }
}
