<?php

declare(strict_types=1);

namespace PhpBorg\Service\Repository;

use PhpBorg\Config\Configuration;
use PhpBorg\Exception\PhpBorgException;
use RuntimeException;

/**
 * Secure encryption and passphrase management
 */
final class EncryptionService
{
    public function __construct(
        private readonly Configuration $config
    ) {
    }

    /**
     * Generate cryptographically secure random passphrase
     *
     * @throws PhpBorgException
     */
    public function generatePassphrase(int $length = 64): string
    {
        try {
            $bytes = random_bytes($length);
            return base64_encode($bytes);
        } catch (\Exception $e) {
            throw new PhpBorgException('Failed to generate secure passphrase', 0, $e);
        }
    }

    /**
     * Encrypt passphrase for storage
     */
    public function encryptPassphrase(string $passphrase): string
    {
        // For database storage, we use TO_BASE64() in SQL
        // This is a simple encoding, not encryption
        // For true encryption, consider using sodium_crypto_secretbox
        return base64_encode($passphrase);
    }

    /**
     * Decrypt passphrase from storage
     */
    public function decryptPassphrase(string $encrypted): string
    {
        $decoded = base64_decode($encrypted, true);
        if ($decoded === false) {
            throw new RuntimeException('Failed to decode passphrase');
        }
        return $decoded;
    }

    /**
     * Generate SSH key pair
     *
     * @return array{publicKey: string, privateKey: string}
     * @throws PhpBorgException
     */
    public function generateSshKeyPair(string $comment = ''): array
    {
        $keyPair = openssl_pkey_new([
            'private_key_bits' => 4096,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($keyPair === false) {
            throw new PhpBorgException('Failed to generate SSH key pair');
        }

        // Export private key
        if (!openssl_pkey_export($keyPair, $privateKey)) {
            throw new PhpBorgException('Failed to export private key');
        }

        // Export public key
        $details = openssl_pkey_get_details($keyPair);
        if ($details === false || !isset($details['key'])) {
            throw new PhpBorgException('Failed to get public key');
        }

        $publicKey = $details['key'];
        if ($comment) {
            $publicKey .= " {$comment}";
        }

        return [
            'publicKey' => $publicKey,
            'privateKey' => $privateKey,
        ];
    }

    /**
     * Hash password securely for storage
     */
    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    /**
     * Verify password against hash
     */
    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
