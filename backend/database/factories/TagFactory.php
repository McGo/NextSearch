<?php

namespace Database\Factories;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'slug' => $slug,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'color' => fake()->randomElement(['neutral', 'primary', 'success', 'warning', 'error', 'info']),
            'scope' => Tag::SCOPE_GLOBAL,
            'assign_policy' => Tag::POLICY_EVERYONE,
            'enabled' => true,
            'position' => 0,
        ];
    }

    /** Assignments stay with whoever made them. */
    public function personal(): static
    {
        return $this->state(fn () => ['scope' => Tag::SCOPE_PERSONAL]);
    }

    /** A tag a user created for themselves. */
    public function ownedBy(User $user): static
    {
        return $this->state(fn () => [
            'owner_id' => $user->id,
            'scope' => Tag::SCOPE_PERSONAL,
            'assign_policy' => Tag::POLICY_EVERYONE,
        ]);
    }

    public function adminOnly(): static
    {
        return $this->state(fn () => ['assign_policy' => Tag::POLICY_ADMIN]);
    }

    /** Hangs on newly indexed documents and falls off again. */
    public function automatic(int $minutes = 10_080): static
    {
        return $this->state(fn () => [
            'auto_rule' => Tag::AUTO_NEW_DOCUMENT,
            'auto_duration_minutes' => $minutes,
        ]);
    }

    public function system(): static
    {
        return $this->state(fn () => [
            'slug' => Tag::SLUG_NEW,
            'name' => null,
            'is_system' => true,
        ]);
    }
}
