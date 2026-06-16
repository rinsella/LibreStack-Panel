<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->default('php'); // static, php, wordpress, node_proxy, reverse_proxy
            $table->string('php_version')->nullable();
            $table->string('document_root');
            $table->string('system_username');
            $table->boolean('www_alias')->default(true);
            $table->string('upstream_url')->nullable();   // reverse/node proxy
            $table->boolean('websocket')->default(false);
            $table->boolean('force_https')->default(false);
            $table->string('status')->default('active'); // active, suspended
            $table->boolean('enabled')->default(true);
            $table->boolean('ssl_enabled')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('website_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->timestamps();
            $table->unique(['website_id', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_aliases');
        Schema::dropIfExists('websites');
    }
};
