<?php

namespace App\Services\Azure\Security;

use App\Models\AzureToken;
use App\Models\User;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Único ponto do sistema que lida com o PAT em texto puro.
 * O model AzureToken nunca expõe o segredo (ciphertext fica em $hidden e
 * não existe accessor que devolva o valor decriptado).
 */
class TokenVault
{
    public function __construct(private readonly TokenCipher $cipher) {}

    /**
     * @param  array<string, mixed>  $attributes  metadados (label, organization, azure_*...)
     */
    public function store(User $user, array $attributes, #[SensitiveParameter] string $pat): AzureToken
    {
        $token = new AzureToken($attributes);
        $token->id = (string) Str::uuid();
        $token->user_id = $user->id;
        $token->token_hint = substr($pat, -4);

        $this->seal($token, $pat);
        $token->save();

        return $token;
    }

    public function reveal(AzureToken $token): string
    {
        return $this->cipher->decrypt($token->ciphertext, $token->key_version, $this->associatedData($token));
    }

    /**
     * Re-criptografa com a chave atual. Retorna false se já estava nela.
     */
    public function rotate(AzureToken $token): bool
    {
        if ($token->key_version === $this->cipher->currentVersion()) {
            return false;
        }

        $this->seal($token, $this->reveal($token));
        $token->save();

        return true;
    }

    private function seal(AzureToken $token, #[SensitiveParameter] string $pat): void
    {
        ['ciphertext' => $ciphertext, 'version' => $version] = $this->cipher->encrypt($pat, $this->associatedData($token));

        $token->ciphertext = $ciphertext;
        $token->key_version = $version;
    }

    private function associatedData(AzureToken $token): string
    {
        return "azure-token|{$token->id}|user:{$token->user_id}";
    }
}
