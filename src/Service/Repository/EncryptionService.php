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
     * Encrypt passphrase for storage (legacy - base64 only)
     */
    public function encryptPassphrase(string $passphrase): string
    {
        return base64_encode($passphrase);
    }

    /**
     * Decrypt passphrase from storage (legacy - base64 only)
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
     * Encrypt sensitive data using AES-256-CBC with APP_SECRET
     */
    public function encrypt(string $plaintext): string
    {
        $key = $this->getEncryptionKey();
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            throw new RuntimeException('Encryption failed');
        }
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt sensitive data using AES-256-CBC with APP_SECRET
     */
    public function decrypt(string $ciphertext): string
    {
        // Check if it looks like encrypted data (base64, min length)
        if (strlen($ciphertext) < 24 || !preg_match('/^[a-zA-Z0-9+\/=]+$/', $ciphertext)) {
            return $ciphertext; // Not encrypted, return as-is
        }

        try {
            $key = $this->getEncryptionKey();
            $data = base64_decode($ciphertext, true);
            if ($data === false || strlen($data) < 17) {
                return $ciphertext; // Not valid encrypted data
            }
            $iv = substr($data, 0, 16);
            $encrypted = substr($data, 16);
            $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            return $decrypted !== false ? $decrypted : $ciphertext;
        } catch (\Exception $e) {
            return $ciphertext; // Return as-is on error
        }
    }

    /**
     * Get encryption key derived from APP_SECRET
     */
    private function getEncryptionKey(): string
    {
        return hash('sha256', $this->config->appSecret, true);
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
