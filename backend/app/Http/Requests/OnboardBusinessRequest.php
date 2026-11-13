<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One-shot onboarding payload. Only the business name is required: a business with no
 * website, no address and no profiles can still start, and we tell it what is missing.
 */
class OnboardBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role check happens in the controller against the active workspace
    }

    public function rules(): array
    {
        return array_merge(
            BusinessRules::business(),
            BusinessRules::nested('aliases', BusinessRules::alias(), 10),
            BusinessRules::nested('locations', array_diff_key(BusinessRules::location(), ['business_location_id' => 1]), 20),
            BusinessRules::nested('contacts', array_diff_key(BusinessRules::contact(), ['business_location_id' => 1]), 20),
            BusinessRules::nested('services', BusinessRules::service(), 50),
            BusinessRules::nested('products', BusinessRules::product(), 100),
            BusinessRules::nested('assets', BusinessRules::asset(), 30),
            ['start_audit' => ['nullable', 'boolean']],
        );
    }
}
