<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stats__daily_visits', function (Blueprint $table): void {
            $table->date('day')->primary();
            $table->unsignedBigInteger('guest')->default(0);
            $table->unsignedBigInteger('member')->default(0);
            $table->unsignedBigInteger('visitors')->default(0);
            $table->unsignedBigInteger('registrations')->default(0);
        });

        Schema::create('stats__page_visits', function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->string('route', 160);
            $table->unsignedBigInteger('guest')->default(0);
            $table->unsignedBigInteger('member')->default(0);
            $table->unique(['day', 'route']);
        });

        Schema::create('stats__referrer_visits', function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->string('domain', 253);
            $table->unsignedBigInteger('visits')->default(0);
            $table->unique(['day', 'domain']);
        });

        Schema::create('stats__client_visits', function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->string('dimension', 20);
            $table->string('client', 20);
            $table->unsignedBigInteger('visits')->default(0);
            $table->unique(['day', 'dimension', 'client']);
        });

        Schema::create('stats__server_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->timestamp('sampled_at')->index();
            $table->unsignedInteger('players')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedBigInteger('uptime_seconds')->nullable();
            $table->decimal('tps', 7, 3)->nullable();
            $table->decimal('cpu_percent', 5, 2)->nullable();
            $table->unsignedTinyInteger('memory_percent')->nullable();
            $table->unsignedBigInteger('memory_used_bytes')->nullable();
            $table->unsignedBigInteger('memory_max_bytes')->nullable();
            $table->unique(['server_id', 'sampled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stats__server_samples');
        Schema::dropIfExists('stats__client_visits');
        Schema::dropIfExists('stats__referrer_visits');
        Schema::dropIfExists('stats__page_visits');
        Schema::dropIfExists('stats__daily_visits');
    }
};
