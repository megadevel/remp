<?php

/*
|--------------------------------------------------------------------------
| Model Factories
|--------------------------------------------------------------------------
|
| Here you may define all of your model factories. Model factories give
| you a convenient way to create models for testing and seeding your
| database. Just tell the factory how a default model should look.
|
*/

/** @var \Illuminate\Database\Eloquent\Factory $factory */
$factory->define(App\Banner::class, function (Faker\Generator $faker) {
    return [
        'uuid' => $faker->uuid,
        'name' => $faker->word,
        'transition' => $faker->randomElement(['fade', 'bounce', 'shake', 'none']),
        'target_url' => $faker->url,
        'position' => $faker->randomElement(['top_left', 'top_right', 'bottom_left', 'bottom_right']),
        'display_delay' => $faker->numberBetween(1000, 5000),
        'display_type' => 'overlay',
        'offset_horizontal' => 0,
        'offset_vertical' => 0,
        'closeable' => $faker->boolean,
        'target_selector' => '#test',
        'manual_events_tracking' => 0,
    ];
});

$factory->define(App\MediumRectangleTemplate::class, function (Faker\Generator $faker) {
    return [
        'header_text' => $faker->words(1, true),
        'main_text' => $faker->words(3, true),
        'button_text' => $faker->words(1, true),
        'text_color' => '#000000',
        'background_color' => '#f7bc1e',
        'button_text_color' => '#ffffff',
        'button_background_color' => '#000000',
    ];
});

$factory->define(App\BarTemplate::class, function (Faker\Generator $faker) {
    return [
        'main_text' => $faker->words(3, true),
        'button_text' => $faker->words(1, true),
        'text_color' => '#000000',
        'background_color' => '#f7bc1e',
        'button_text_color' => '#ffffff',
        'button_background_color' => '#000000',
    ];
});

$factory->define(App\ShortMessageTemplate::class, function (Faker\Generator $faker) {
    return [
        'text' => $faker->words(3, true),
        'text_color' => '#000000',
        'background_color' => '#f7bc1e',
    ];
});

$factory->define(App\Campaign::class, function (Faker\Generator $faker) {
    return [
        'name' => $faker->words(2, true),
        'uuid' => $faker->uuid,
        'pageview_rules' => [],
        'devices' => [],
        'signed_in' => $faker->boolean(),
        'once_per_session' => $faker->boolean(),
        'url_filter' => 'everywhere',
        'referer_filter' => 'everywhere',
    ];
});

$factory->define(App\CampaignSegment::class, function (Faker\Generator $faker) {
    return [
        'campaign_id' => 1,
        'code' => 'demo_segment',
        'provider' => 'remp_segment',
    ];
});

$factory->define(App\CampaignBanner::class, function (Faker\Generator $faker) {
    return [
        'control_group' => 0,
        'proportion' => 100,
        'weight' => 1,
        'uuid' => $faker->uuid,
    ];
});
