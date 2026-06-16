<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_databases', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('driver')->default('mysql'); // mysql/mariadb
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamps();
        });

        Schema::create('database_users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('host')->default('localhost');
            $table->foreignId('panel_database_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_users');
        Schema::dropIfExists('panel_databases');
    }
};
