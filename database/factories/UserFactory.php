<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * An administrator created for a test holds the same baseline the permission
     * migration gave every administrator already in the database, so an admin screen
     * keeps being tested by the test that was written for it.
     *
     * A test that wants a narrower admin deletes the rows it wants gone
     * ($admin->permissionGrants()->whereIn('permission', [...])->delete()) rather
     * than asking for a different kind of account, because in production the grant
     * table is the only place the answer lives.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if (!$user->is_admin || $user->isMasterAdmin()) {
                return;
            }

            $now = now();

            $user->permissionGrants()->insert(collect(AdminPermissions::slugs())
                ->map(fn (string $slug): array => [
                    'user_id' => (int) $user->id,
                    'permission' => $slug,
                    'granted_by_user_id' => null,
                    'note' => AdminPermissions::baselineNote(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all());
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'name' => fn (array $attributes) => trim(($attributes['first_name'] ?? '').' '.($attributes['last_name'] ?? '')),
            'phone' => '0'.fake()->numerify('8#########'),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
