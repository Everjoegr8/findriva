<?php

namespace App\Http\Requests;

use App\Enums\AssetPlatform;
use Illuminate\Validation\Rule;

/** Validation rules shared between onboarding (nested) and the per-record endpoints. */
final class BusinessRules
{
    public static function business(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'primary_category' => ['nullable', 'string', 'max:120'],
            'secondary_categories' => ['nullable', 'array', 'max:9'],
            'secondary_categories.*' => ['string', 'max:120'],
            'country' => [$partial ? 'sometimes' : 'nullable', 'string', 'size:2'],
            'default_language' => ['nullable', 'string', 'max:10'],
            'website_url' => ['nullable', 'url:http,https', 'max:500'],
        ];
    }

    public static function alias(): array
    {
        return [
            'alias' => ['required', 'string', 'max:160'],
            'type' => ['nullable', Rule::in(['trading_name', 'abbreviation', 'former_name', 'misspelling'])],
        ];
    }

    public static function location(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:120'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'address_line2' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'location_type' => ['nullable', Rule::in(['storefront', 'service_area', 'hybrid', 'online'])],
            'address_is_public' => ['nullable', 'boolean'],
            'service_radius_km' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'service_areas' => ['nullable', 'array', 'max:50'],
            'service_areas.*' => ['string', 'max:120'],
            'opening_hours' => ['nullable', 'array'],
            'opening_hours.*' => ['nullable', 'array'],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }

    public static function contact(): array
    {
        return [
            'type' => ['required', Rule::in(['phone', 'email', 'whatsapp', 'url'])],
            'value' => ['required', 'string', 'max:255'],
            'is_primary' => ['nullable', 'boolean'],
            'business_location_id' => ['nullable', 'integer'],
        ];
    }

    public static function service(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'service_areas' => ['nullable', 'array', 'max:50'],
            'service_areas.*' => ['string', 'max:120'],
            'url' => ['nullable', 'url:http,https', 'max:500'],
        ];
    }

    public static function product(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'url' => ['nullable', 'url:http,https', 'max:500'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }

    public static function asset(): array
    {
        return [
            'url' => ['required', 'url:http,https', 'max:500'],
            'platform' => ['nullable', Rule::enum(AssetPlatform::class)],
            'handle' => ['nullable', 'string', 'max:120'],
            'ownership_status' => ['nullable', Rule::in(['claimed', 'unverified', 'not_owned'])],
        ];
    }

    /** Prefix rules for nested arrays in the onboarding payload. */
    public static function nested(string $key, array $rules, int $max): array
    {
        $out = [$key => ['nullable', 'array', 'max:'.$max]];
        foreach ($rules as $field => $r) {
            $out["{$key}.*.{$field}"] = $r;
        }

        return $out;
    }
}
