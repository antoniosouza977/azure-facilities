<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('azure_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('label', 60);
            $table->string('organization', 100);

            // Segredo: nonce + ciphertext (XChaCha20-Poly1305) em base64.
            $table->text('ciphertext');
            $table->unsignedSmallInteger('key_version');
            // Últimos 4 caracteres, só para o usuário reconhecer o token.
            $table->string('token_hint', 4);

            // Identidade do dono do PAT no Azure (vem do connectionData).
            $table->uuid('azure_user_id');
            $table->string('azure_display_name')->nullable();

            $table->boolean('is_default')->default(false);
            $table->date('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'label']);
            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('azure_tokens');
    }
};
