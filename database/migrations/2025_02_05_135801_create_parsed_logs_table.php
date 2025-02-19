<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('parsed_logs', function (Blueprint $table) {
            $table->id();
            $table->integer('parsed_ip_id')->nullable()->index(); // Индекс на ipclient_id
            $table->integer('parsed_url_id')->nullable()->index(); // Индекс на ipclient_id
            $table->integer('parsed_referer_id')->nullable()->index(); // Индекс на ipclient_id
            $table->string('ip_address')->nullable()->index(); // Индекс на ip_address
            $table->dateTime('time')->nullable()->index(); // Индекс на time
            $table->string('action')->nullable()->index(); // Индекс на action
            $table->string('url')->nullable();
            $table->string('status_code')->nullable()->index(); // Индекс на status_code
            $table->integer('size')->nullable();
            $table->string('protocol')->nullable();
            $table->string('http_referer')->nullable();
            $table->string('user_agent')->nullable();
            // $table->timestamps();

            // // Составные индексы для наиболее вероятных комбинаций
            // $table->index(['ip_address', 'time']); // Индекс на комбинацию ip_address и time
            // $table->index(['ipclient_id', 'time']); // Индекс на комбинацию ipclient_id и time
            // $table->index(['status_code', 'time']); // Индекс на комбинацию status_code и time
            // $table->index(['action', 'time']); // Индекс на комбинацию action и time
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('request_logs');
    }
};
