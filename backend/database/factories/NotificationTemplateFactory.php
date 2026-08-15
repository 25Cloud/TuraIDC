<?php

namespace Database\Factories;

use App\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationTemplate>
 */
class NotificationTemplateFactory extends Factory
{
    protected $model = NotificationTemplate::class;

    public function definition(): array
    {
        $channel = $this->faker->randomElement(['email', 'sms']);

        return [
            'channel' => $channel,
            'code' => (string) $this->faker->unique()->numberBetween(900001, 999999),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'audience' => 'user',
            'subject' => $channel === 'email' ? $this->faker->sentence(4) : null,
            'content' => '测试模板 {{name}}',
            'variables_json' => ['name'],
            'provider_variables_json' => $channel === 'sms' ? ['content'] : [],
            'provider_template_id' => null,
            'is_enabled' => true,
            'is_custom' => false,
            'sort_order' => 0,
        ];
    }
}
