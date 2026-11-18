<?php

use App\Models\AzureToken;
use App\Services\Azure\Security\TokenCipher;
use App\Services\Azure\Security\TokenVault;
use Illuminate\Support\Facades\Artisan;

Artisan::command('azure:generate-key', function () {
    $this->line(TokenCipher::generateKey());
    $this->comment('Adicione em AZURE_TOKEN_KEYS como "<versão>:<chave>". Guarde fora do repositório.');
})->purpose('Gera uma chave nova para criptografar os PATs do Azure');

Artisan::command('azure:rotate-tokens', function (TokenVault $vault, TokenCipher $cipher) {
    $rotated = 0;
    $failed = 0;

    AzureToken::query()->where('key_version', '!=', $cipher->currentVersion())->lazyById(100, 'id')
        ->each(function (AzureToken $token) use ($vault, &$rotated, &$failed) {
            try {
                $vault->rotate($token) && $rotated++;
            } catch (Throwable $e) {
                $failed++;
                $this->error("Token {$token->id}: {$e->getMessage()}");
            }
        });

    $this->info("Re-criptografados com a chave v{$cipher->currentVersion()}: {$rotated}. Falhas: {$failed}.");

    return $failed === 0 ? 0 : 1;
})->purpose('Re-criptografa os PATs com a chave atual (AZURE_TOKEN_KEY_CURRENT)');
