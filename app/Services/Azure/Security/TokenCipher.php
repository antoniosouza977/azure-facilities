<?php

namespace App\Services\Azure\Security;

use RuntimeException;
use SensitiveParameter;

/**
 * Criptografia autenticada (AEAD) dos PATs do Azure DevOps.
 *
 * - Algoritmo: XChaCha20-Poly1305 (libsodium) com nonce aleatório de 192 bits.
 * - Chaves próprias (AZURE_TOKEN_KEYS), separadas da APP_KEY: vazar/rotacionar
 *   a APP_KEY (cookies, sessão) não expõe os tokens, e vice-versa.
 * - Chaves versionadas: cada registro guarda a versão usada, permitindo
 *   rotação sem downtime (ver comando azure:rotate-tokens).
 * - Associated data (AAD): o ciphertext é amarrado ao id do registro e ao
 *   dono. Copiar o ciphertext de um usuário para outra linha/usuário no banco
 *   faz a decriptação falhar.
 */
final class TokenCipher
{
    /** @var array<int, string> chaves binárias de 32 bytes, por versão */
    private array $keys = [];

    /**
     * @param  array<int, string>  $base64Keys
     */
    public function __construct(#[SensitiveParameter] array $base64Keys, private readonly int $currentVersion)
    {
        foreach ($base64Keys as $version => $encoded) {
            $key = base64_decode($encoded, true);

            if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new RuntimeException("AZURE_TOKEN_KEYS: a chave da versão {$version} precisa ser base64 de 32 bytes.");
            }

            $this->keys[(int) $version] = $key;
        }
    }

    public function currentVersion(): int
    {
        return $this->currentVersion;
    }

    /**
     * @return array{ciphertext: string, version: int}
     */
    public function encrypt(#[SensitiveParameter] string $plaintext, string $associatedData): array
    {
        $key = $this->key($this->currentVersion);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $associatedData, $nonce, $key);

        return [
            'ciphertext' => base64_encode($nonce.$ciphertext),
            'version' => $this->currentVersion,
        ];
    }

    public function decrypt(string $payload, int $version, string $associatedData): string
    {
        $key = $this->key($version);
        $raw = base64_decode($payload, true);
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        if ($raw === false || strlen($raw) <= $nonceLength) {
            throw new TokenDecryptionException('Payload do token inválido.');
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($raw, $nonceLength),
            $associatedData,
            substr($raw, 0, $nonceLength),
            $key,
        );

        if ($plaintext === false) {
            throw new TokenDecryptionException('Não foi possível decriptar o token (chave incorreta ou dado adulterado).');
        }

        return $plaintext;
    }

    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_aead_xchacha20poly1305_ietf_keygen());
    }

    private function key(int $version): string
    {
        if (! isset($this->keys[$version])) {
            throw new TokenDecryptionException("Chave de criptografia versão {$version} não configurada em AZURE_TOKEN_KEYS.");
        }

        return $this->keys[$version];
    }

    public function __debugInfo(): array
    {
        return ['versions' => array_keys($this->keys), 'current' => $this->currentVersion];
    }

    public function __serialize(): array
    {
        throw new RuntimeException('TokenCipher não pode ser serializado.');
    }
}
