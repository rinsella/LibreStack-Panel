<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation'); // upload, delete, move, copy, zip, ...
            $table->string('path');
            $table->string('target_path')->nullable();
            $table->string('status')->default('success');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_operations');
    }
};
