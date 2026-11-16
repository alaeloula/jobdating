<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Comptes fictifs pour les tests (aucune donnée personnelle réelle).
 *
 * Règle : le rôle s'attribue après l'enregistrement (afterCreating, jamais afterMaking),
 * voir User::setRole().
 *
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
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'username' => null,
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'password' => static::$password ??= Hash::make('password'),
            'activated_at' => now(),
            'last_login_at' => null,
            'deactivated_at' => null,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Compte créé mais pas encore activé (AUTH-02).
     */
    public function unactivated(): static
    {
        return $this->state(fn (array $attributes) => [
            'activated_at' => null,
        ]);
    }

    /**
     * Compte désactivé (RG-ACC-05).
     */
    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => [
            'deactivated_at' => now(),
        ]);
    }

    /**
     * Compte avec son rôle, attribué après l'enregistrement.
     */
    public function withRole(Role $role): static
    {
        $state = $role === Role::Entreprise
            ? $this->state(fn (array $attributes) => [
                'username' => 'entreprise-'.Str::lower(Str::random(8)),
                'email' => null,
            ])
            : $this;

        return $state->afterCreating(fn (User $user) => $user->setRole($role));
    }
}
