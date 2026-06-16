<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('status')->default('queued'); // queued, running, success, failed
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('message')->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('job_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_job_id')->constrained()->cascadeOnDelete();
            $table->string('level')->default('info');
            $table->text('line');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_logs');
        Schema::dropIfExists('system_jobs');
    }
};
