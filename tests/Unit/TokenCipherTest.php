<?php

namespace Tests\Unit;

use App\Services\Azure\Security\TokenCipher;
use App\Services\Azure\Security\TokenDecryptionException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TokenCipherTest extends TestCase
{
    private const PAT = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcdefghij';

    private function cipher(int $current = 1): TokenCipher
    {
        return new TokenCipher([
            1 => base64_encode(str_repeat('a', 32)),
            2 => base64_encode(str_repeat('b', 32)),
        ], $current);
    }

    public function test_encrypts_and_decrypts(): void
    {
        $cipher = $this->cipher();
        $sealed = $cipher->encrypt(self::PAT, 'aad');

        $this->assertSame(1, $sealed['version']);
        $this->assertStringNotContainsString(self::PAT, $sealed['ciphertext']);
        $this->assertSame(self::PAT, $cipher->decrypt($sealed['ciphertext'], 1, 'aad'));
    }

    public function test_uses_random_nonce(): void
    {
        $cipher = $this->cipher();

        $this->assertNotSame($cipher->encrypt(self::PAT, 'aad')['ciphertext'], $cipher->encrypt(self::PAT, 'aad')['ciphertext']);
    }

    public function test_rejects_different_associated_data(): void
    {
        $cipher = $this->cipher();
        $sealed = $cipher->encrypt(self::PAT, 'azure-token|A|user:1');

        $this->expectException(TokenDecryptionException::class);
        $cipher->decrypt($sealed['ciphertext'], 1, 'azure-token|A|user:2');
    }

    public function test_rejects_tampered_ciphertext(): void
    {
        $cipher = $this->cipher();
        $raw = base64_decode($cipher->encrypt(self::PAT, 'aad')['ciphertext']);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);

        $this->expectException(TokenDecryptionException::class);
        $cipher->decrypt(base64_encode($raw), 1, 'aad');
    }

    public function test_rejects_wrong_key_version(): void
    {
        $cipher = $this->cipher();
        $sealed = $cipher->encrypt(self::PAT, 'aad');

        $this->expectException(TokenDecryptionException::class);
        $cipher->decrypt($sealed['ciphertext'], 2, 'aad');
    }

    public function test_decrypts_old_version_after_rotation(): void
    {
        $sealed = $this->cipher(1)->encrypt(self::PAT, 'aad');
        $rotated = $this->cipher(2);

        $this->assertSame(self::PAT, $rotated->decrypt($sealed['ciphertext'], 1, 'aad'));
        $this->assertSame(2, $rotated->encrypt(self::PAT, 'aad')['version']);
    }

    public function test_rejects_invalid_key(): void
    {
        $this->expectException(RuntimeException::class);
        new TokenCipher([1 => base64_encode('curta')], 1);
    }

    public function test_does_not_leak_keys_in_dumps(): void
    {
        $this->assertStringNotContainsString(str_repeat('a', 32), print_r($this->cipher(), true));
    }
}
