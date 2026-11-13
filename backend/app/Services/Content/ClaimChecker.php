<?php

namespace App\Services\Content;

use App\Support\Phone;

/**
 * Checks a draft against the approved facts before anyone can approve it. "block" flags stop
 * approval (placeholders, contact details or prices we cannot trace to a fact, ranking promises).
 * "warn" flags ask the reviewer to confirm a claim they must be able to back up.
 *
 * @phpstan-type Flag array{severity: 'block'|'warn', kind: string, text: string, message: string}
 */
class ClaimChecker
{
    private const RANKING = ['guarantee', 'guaranteed', 'rank #1', 'rank first', 'number one on google', '#1 on google', 'top of google', 'first page of google', 'page one of google'];

    private const NEEDS_EVIDENCE = ['best', 'number one', '#1', 'no. 1', 'award', 'award-winning', 'certified', 'licensed', 'accredited', 'leading', 'top-rated', 'cheapest', 'fastest', 'largest', 'trusted by', '100%'];

    /** @return list<array{severity: string, kind: string, text: string, message: string}> */
    public function check(string $body, array $facts, string $type): array
    {
        $flags = [];
        $flag = function (string $severity, string $kind, string $text, string $message) use (&$flags) {
            $flags[] = compact('severity', 'kind', 'text', 'message');
        };
        $lower = mb_strtolower($body);
        $factText = mb_strtolower(json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $factDigits = preg_replace('/\D+/', '', $factText);

        preg_match_all('/\[ADD:[^\]]*\]/i', $body, $m);
        foreach (array_unique($m[0]) as $ph) {
            $flag('block', 'placeholder', $ph, 'Replace this placeholder with the real detail, or remove the sentence.');
        }

        foreach (self::RANKING as $phrase) {
            if (str_contains($lower, $phrase)) {
                $flag('block', 'ranking_promise', $phrase, 'Remove promises about guarantees or search rankings. Nobody can guarantee them.');
            }
        }

        // Phone numbers must be ones the business gave us.
        $known = array_map(fn ($p) => Phone::nationalDigits($p), array_merge($facts['phones'], $facts['whatsapp']));
        preg_match_all('/(?:\+?\d[\d\s().-]{7,}\d)/', $body, $m);
        foreach (array_unique($m[0]) as $candidate) {
            $digits = preg_replace('/\D+/', '', $candidate);
            if (strlen($digits) < 9 || preg_match('/^(19|20)\d{2}$/', $digits)) {
                continue;
            }
            if (! in_array(Phone::nationalDigits($candidate), $known, true)) {
                $flag('block', 'unknown_phone', trim($candidate), 'This phone number is not in your business details.');
            }
        }

        preg_match_all('/[\w.+-]+@[\w-]+\.[\w.-]+/u', $body, $m);
        foreach (array_unique(array_map(fn ($e) => rtrim($e, '.'), $m[0])) as $email) {
            if (! in_array(mb_strtolower($email), array_map('mb_strtolower', $facts['emails']), true)) {
                $flag('block', 'unknown_email', $email, 'This email address is not in your business details.');
            }
        }

        preg_match_all('#https?://[^\s)\]]+#i', $body, $m);
        foreach (array_unique($m[0]) as $url) {
            if (! str_contains($factText, mb_strtolower(rtrim($url, '/.,')))) {
                $flag('block', 'unknown_link', $url, 'This link is not one of your website or profiles.');
            }
        }

        // Prices: any currency amount must match a product price.
        preg_match_all('/(?:₦|ngn|\bn(?=\d)|\$|usd|£|€)\s?\d[\d,]*(?:\.\d+)?|\d[\d,]*(?:\.\d+)?\s?(?:naira|ngn)/iu', $body, $m);
        $prices = array_map(fn ($p) => (float) $p['price'], array_filter($facts['products'], fn ($p) => $p['price'] !== null));
        foreach (array_unique($m[0]) as $amount) {
            $value = (float) str_replace(',', '', preg_replace('/[^\d.,]/', '', $amount));
            if ($value > 0 && ! in_array($value, $prices, false)) {
                $flag('block', 'unknown_price', $amount, 'This price is not on any of your products.');
            }
        }

        // Other numbers ("10 years", "500 customers", "since 2015") need to come from the facts.
        preg_match_all('/\b(?:since\s+(?:19|20)\d{2}|\d[\d,]*\+?\s*(?:years?|customers?|clients?|orders?|branches|staff|%|percent|reviews?|stars?))/iu', $body, $m);
        foreach (array_unique($m[0]) as $claim) {
            $digits = preg_replace('/\D+/', '', $claim);
            if ($digits !== '' && ! str_contains($factDigits, $digits)) {
                $flag('warn', 'unsupported_number', $claim, 'Check this figure is true. It is not in your business details.');
            }
        }

        foreach (self::NEEDS_EVIDENCE as $word) {
            if (preg_match('/(?<![\w-])'.preg_quote($word, '/').'(?![\w-])/iu', $body) && ! str_contains($factText, $word)) {
                $flag('warn', 'needs_evidence', $word, 'Only keep "'.$word.'" if you can prove it, for example with an award or licence.');
            }
        }

        $max = ContentTypes::get($type)['max_chars'];
        if (mb_strlen($body) > $max) {
            $flag('block', 'too_long', mb_strlen($body).' characters', "This is longer than the {$max} characters allowed here.");
        }
        if ($type === 'google_profile_description' && preg_match('#https?://|www\.#i', $body)) {
            $flag('block', 'google_policy', 'link', 'Google does not allow links in the business description.');
        }
        if ($type === 'google_profile_description' && preg_match('/\+?\d[\d\s().-]{7,}\d/', $body)) {
            $flag('block', 'google_policy', 'phone number', 'Google does not allow phone numbers in the business description. Add it in the phone field instead.');
        }

        return $flags;
    }

    public static function blocks(array $flags): bool
    {
        return collect($flags)->contains(fn ($f) => $f['severity'] === 'block');
    }
}
