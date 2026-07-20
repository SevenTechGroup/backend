<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_locations', function (Blueprint $table) {
            $table->foreignId('report_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy_m', 10, 2);
            $table->string('source')->default('gps');
        });

        Schema::table('consent_records', function (Blueprint $table) {
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('consent_type');
            $table->timestamp('granted_at');
            $table->unique(['report_id', 'user_id', 'consent_type']);
        });

        Schema::table('attachments', function (Blueprint $table) {
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('cloudinary');
            $table->string('provider_asset_id')->nullable()->index();
            $table->string('provider_public_id')->index();
            $table->string('resource_type');
            $table->string('delivery_type')->default('authenticated');
            $table->string('format')->nullable();
            $table->string('mime_type');
            $table->string('original_filename');
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->text('secure_url');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropForeign(['report_id']);
            $table->dropColumn([
                'report_id',
                'provider',
                'provider_asset_id',
                'provider_public_id',
                'resource_type',
                'delivery_type',
                'format',
                'mime_type',
                'original_filename',
                'bytes',
                'width',
                'height',
                'secure_url',
            ]);
        });

        Schema::table('consent_records', function (Blueprint $table) {
            $table->dropUnique(['report_id', 'user_id', 'consent_type']);
            $table->dropForeign(['report_id']);
            $table->dropForeign(['user_id']);
            $table->dropColumn(['report_id', 'user_id', 'consent_type', 'granted_at']);
        });

        Schema::table('report_locations', function (Blueprint $table) {
            $table->dropForeign(['report_id']);
            $table->dropColumn(['report_id', 'latitude', 'longitude', 'accuracy_m', 'source']);
        });
    }
};
