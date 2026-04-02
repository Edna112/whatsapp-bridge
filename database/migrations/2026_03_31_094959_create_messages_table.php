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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->onDelete('cascade');
            $table->enum('direction', ['inbound', 'outbound']);
            $table->text('message_body');
            $table->enum('status', ['received', 'queued', 'sent', 'delivered', 'failed']);
            $table->string('whatsapp_message_id')->nullable();
            $table->timestamps();

            // Fast indexing for loading the chat dashboard
            $table->index(['contact_id', 'created_at']);
        });

        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
