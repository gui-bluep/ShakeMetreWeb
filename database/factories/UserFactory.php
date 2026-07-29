<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // The column defaults to `readonly` - the safe default for a real row, since a
            // ShakeDesign privilege set we do not recognise must not gain write access. A
            // factory user stands for an ordinary user, so it is granted write here instead
            // of every test having to opt in.
            'role' => UserRole::User,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin]);
    }

    public function readOnly(): static
    {
        return $this->state(fn () => ['role' => UserRole::ReadOnly]);
    }

    /** A user that came in through ShakeDesign SSO rather than Breeze registration. */
    public function fromShakeDesign(string $zkp): static
    {
        return $this->state(fn () => ['shakedesign_user_id' => $zkp]);
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
