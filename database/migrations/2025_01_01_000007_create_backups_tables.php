<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->string('domain')->nullable();
            $table->string('type')->default('files'); // files, database, full
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('status')->default('pending'); // pending, running, success, failed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('backup_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->default('full');
            $table->string('frequency')->default('daily'); // daily, weekly, monthly
            $table->unsignedTinyInteger('retention')->default(7);
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_schedules');
        Schema::dropIfExists('backups');
    }
};
