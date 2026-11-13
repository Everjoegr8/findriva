<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Google Maps results checked from a grid of points around a location: where nearby searchers see the business.
        Schema::create('local_grid_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('keyword_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('business_location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('keyword');
            $table->decimal('center_lat', 10, 7);
            $table->decimal('center_lng', 10, 7);
            $table->unsignedTinyInteger('size');        // 3 or 5 (points per side)
            $table->decimal('spacing_km', 5, 2);
            $table->unsignedSmallInteger('depth');      // results looked at per point
            $table->string('provider', 30);
            $table->string('status', 20)->default('queued'); // queued, running, completed, partial, failed
            $table->json('points')->nullable();         // [{row, col, lat, lng, position|null, top: [...]}]
            $table->unsignedSmallInteger('points_found')->default(0);
            $table->decimal('average_position', 5, 2)->nullable(); // over points where found
            $table->text('error')->nullable();
            $table->decimal('cost', 10, 5)->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_grid_scans');
    }
};
