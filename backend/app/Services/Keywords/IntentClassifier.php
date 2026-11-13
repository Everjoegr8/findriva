<?php

namespace App\Services\Keywords;

/** Rule-based search intent. Transparent on purpose: each label can be explained to the owner. */
class IntentClassifier
{
    private const INFORMATIONAL = ['how', 'what', 'why', 'when', 'which', 'who', 'tips', 'guide', 'ideas', 'meaning', 'benefits', 'difference', 'tutorial', 'learn', 'diy'];

    private const COMMERCIAL = ['best', 'top', 'review', 'reviews', 'vs', 'compare', 'cheap', 'cheapest', 'affordable', 'price', 'prices', 'cost', 'rates', 'quality'];

    private const TRANSACTIONAL = ['buy', 'order', 'book', 'booking', 'hire', 'delivery', 'shop', 'sale', 'for sale', 'rent', 'subscribe', 'appointment', 'quote'];

    /** @param list<string> $brandNames @param list<string> $places */
    public function classify(string $phrase, array $brandNames = [], array $places = []): string
    {
        $p = ' '.mb_strtolower(trim($phrase)).' ';
        $norm = fn ($s) => preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s));
        foreach ($brandNames as $b) {
            if (mb_strlen($norm($b)) >= 3 && str_contains($norm($p), $norm($b))) {
                return 'navigational';
            }
        }
        if (self::has($p, self::INFORMATIONAL)) {
            return 'informational';
        }
        if (str_contains($p, ' near me ') || str_contains($p, ' nearby ') || str_contains($p, ' around ')) {
            return 'local';
        }
        foreach ($places as $place) {
            if ($place !== '' && str_contains($p, ' '.mb_strtolower($place).' ')) {
                return self::has($p, self::TRANSACTIONAL) ? 'transactional' : 'local';
            }
        }
        if (self::has($p, self::TRANSACTIONAL)) {
            return 'transactional';
        }

        return 'commercial';
    }

    private static function has(string $p, array $words): bool
    {
        foreach ($words as $w) {
            if (str_contains($p, ' '.$w.' ')) {
                return true;
            }
        }

        return false;
    }
}
