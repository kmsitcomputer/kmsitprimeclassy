<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'role_id' => Role::query()->where('slug', 'konsumen')->value('id'),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('08##########'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'status' => 'active',
        ];
    }

    private function withRole(string $slug): static
    {
        return $this->state(fn () => ['role_id' => Role::query()->where('slug', $slug)->value('id')]);
    }

    public function superAdmin(): static
    {
        return $this->withRole('super_admin');
    }

    public function agen(): static
    {
        return $this->withRole('agen');
    }

    public function korsal(): static
    {
        return $this->withRole('korsal');
    }

    public function sales(): static
    {
        return $this->withRole('sales');
    }

    public function konsumen(): static
    {
        return $this->withRole('konsumen');
    }

    public function admin(): static
    {
        return $this->withRole('admin');
    }

    public function keuangan(): static
    {
        return $this->withRole('keuangan');
    }

    public function kurir(): static
    {
        return $this->withRole('kurir');
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
