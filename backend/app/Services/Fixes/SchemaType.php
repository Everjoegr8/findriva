<?php

namespace App\Services\Fixes;

/** Maps a free-text business category to the closest schema.org type. */
class SchemaType
{
    /** Ordered: the first matching keyword wins, so specific types come before general ones. */
    private const MAP = [
        'barber' => 'HairSalon', 'hair' => 'HairSalon', 'salon' => 'BeautySalon', 'nail' => 'NailSalon', 'spa' => 'DaySpa',
        'makeup' => 'BeautySalon', 'beauty' => 'BeautySalon', 'bakery' => 'Bakery', 'cake' => 'Bakery', 'cafe' => 'CafeOrCoffeeShop',
        'coffee' => 'CafeOrCoffeeShop', 'bar' => 'BarOrPub', 'lounge' => 'BarOrPub', 'restaurant' => 'Restaurant', 'kitchen' => 'Restaurant',
        'buka' => 'Restaurant', 'catering' => 'FoodEstablishment', 'food' => 'FoodEstablishment', 'hotel' => 'Hotel', 'shortlet' => 'LodgingBusiness',
        'apartment' => 'LodgingBusiness', 'dentist' => 'Dentist', 'dental' => 'Dentist', 'pharmacy' => 'Pharmacy', 'chemist' => 'Pharmacy',
        'clinic' => 'MedicalClinic', 'hospital' => 'MedicalClinic', 'doctor' => 'Physician', 'lawyer' => 'LegalService', 'law' => 'LegalService',
        'legal' => 'LegalService', 'accountant' => 'AccountingService', 'accounting' => 'AccountingService', 'plumb' => 'Plumber',
        'electric' => 'Electrician', 'mechanic' => 'AutoRepair', 'auto' => 'AutoRepair', 'car wash' => 'AutoWash', 'car' => 'AutomotiveBusiness',
        'real estate' => 'RealEstateAgent', 'property' => 'RealEstateAgent', 'travel' => 'TravelAgency', 'gym' => 'ExerciseGym',
        'fitness' => 'ExerciseGym', 'school' => 'School', 'tutor' => 'EducationalOrganization', 'lesson' => 'EducationalOrganization',
        'fashion' => 'ClothingStore', 'cloth' => 'ClothingStore', 'boutique' => 'ClothingStore', 'tailor' => 'ClothingStore',
        'grocery' => 'GroceryStore', 'supermarket' => 'GroceryStore', 'phone' => 'ElectronicsStore', 'electronic' => 'ElectronicsStore',
        'gadget' => 'ElectronicsStore', 'furniture' => 'FurnitureStore', 'laundry' => 'DryCleaningOrLaundry', 'dry clean' => 'DryCleaningOrLaundry',
        'photograph' => 'ProfessionalService', 'event' => 'ProfessionalService', 'clean' => 'HomeAndConstructionBusiness',
        'construction' => 'HomeAndConstructionBusiness', 'interior' => 'HomeAndConstructionBusiness', 'logistics' => 'LocalBusiness',
        'shop' => 'Store', 'store' => 'Store',
    ];

    private const WHOLE_WORDS = ['bar', 'car', 'spa', 'law', 'auto', 'food', 'cake', 'buka', 'gym', 'shop', 'store', 'event'];

    public static function for(?string $category, bool $onlineOnly = false): string
    {
        $c = mb_strtolower((string) $category);
        foreach (self::MAP as $needle => $type) {
            // Short words must match whole ("car" must not match "skin care"); stems match prefixes.
            $pattern = in_array($needle, self::WHOLE_WORDS, true) ? '/\\b'.preg_quote($needle, '/').'\\b/u' : '/\\b'.preg_quote($needle, '/').'/u';
            if (preg_match($pattern, $c)) {
                return $type;
            }
        }

        return $onlineOnly ? 'OnlineBusiness' : 'LocalBusiness';
    }

    /** Lower-case names of every type this mapper can emit (the audit accepts all of them). */
    public static function all(): array
    {
        return array_values(array_unique(array_map('strtolower', array_merge(array_values(self::MAP), ['LocalBusiness', 'OnlineBusiness']))));
    }
}
