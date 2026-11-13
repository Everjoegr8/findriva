<?php

namespace Tests\Unit;

use App\Enums\AssetPlatform;
use App\Support\Phone;
use PHPUnit\Framework\TestCase;

class PhoneAndPlatformTest extends TestCase
{
    public function test_nigerian_numbers_normalise_to_e164(): void
    {
        $this->assertSame('+2348031234567', Phone::normalize('0803 123 4567'));
        $this->assertSame('+2348031234567', Phone::normalize('+234 803-123-4567'));
        $this->assertSame('+2348031234567', Phone::normalize('2348031234567'));
        $this->assertSame('+447700900123', Phone::normalize('+44 7700 900123'));
        $this->assertSame('031234567', Phone::nationalDigits('+2348031234567'));
    }

    public function test_detects_platform_from_url(): void
    {
        $this->assertSame(AssetPlatform::Instagram, AssetPlatform::detect('https://www.instagram.com/glowbyada'));
        $this->assertSame(AssetPlatform::TikTok, AssetPlatform::detect('https://tiktok.com/@glow'));
        $this->assertSame(AssetPlatform::WhatsApp, AssetPlatform::detect('https://wa.me/2348031234567'));
        $this->assertSame(AssetPlatform::Shopify, AssetPlatform::detect('https://glow.myshopify.com'));
        $this->assertSame(AssetPlatform::Website, AssetPlatform::detect('https://glowbyada.ng'));
    }
}
