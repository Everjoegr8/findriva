<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->text('description')->nullable();
            $table->string('primary_category')->nullable();
            $table->json('secondary_categories')->nullable();
            $table->string('country', 2)->default('NG');
            $table->string('default_language', 10)->default('en');
            $table->string('website_url')->nullable();
            $table->string('status', 20)->default('onboarding');
            $table->timestamp('onboarded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('business_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('alias');
            $table->string('type', 30)->default('trading_name'); // trading_name, abbreviation, former_name, misspelling
            $table->string('source', 30)->default('owner');
            $table->timestamps();
        });

        Schema::create('business_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 2);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            // storefront = customers visit; service_area = business travels to customers; hybrid = both
            $table->string('location_type', 20)->default('storefront');
            $table->boolean('address_is_public')->default(true);
            $table->unsignedInteger('service_radius_km')->nullable();
            $table->json('service_areas')->nullable();
            $table->json('opening_hours')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('business_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // phone, email, whatsapp, url
            $table->string('value');
            $table->string('normalized_value')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('business_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('service_areas')->nullable();
            $table->string('url')->nullable();
            $table->timestamps();
        });

        Schema::create('business_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('url')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('digital_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 30);
            $table->string('url');
            $table->string('handle')->nullable();
            // claimed = owner says it is theirs; verified = proven; unverified; not_owned (e.g. a directory listing)
            $table->string('ownership_status', 20)->default('claimed');
            // not_connected, manual, connected, error, revoked
            $table->string('connection_state', 20)->default('not_connected');
            $table->string('scan_status', 20)->default('pending');
            $table->json('scan_data')->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'url']);
        });

        Schema::create('domain_properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->string('canonical_host')->nullable();
            $table->string('verification_status', 20)->default('unverified');
            $table->string('verification_token', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'domain']);
        });
    }

    public function down(): void
    {
        foreach (['domain_properties', 'digital_assets', 'business_products', 'business_services',
            'business_contacts', 'business_locations', 'business_aliases', 'businesses'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
