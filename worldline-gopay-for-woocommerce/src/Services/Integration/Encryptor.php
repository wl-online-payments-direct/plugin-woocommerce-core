<?php

namespace WOP\OnlinePayments\Services\Integration;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Encryption\Encryptor as CoreEncryptor;
defined('ABSPATH') || exit;
/**
 * Encrypts/decrypts connection secrets with AES-256-CBC, keyed from the WordPress salts.
 */
class Encryptor implements CoreEncryptor
{
    private const CIPHER = 'aes-256-cbc';
    /**
     * @inheritDoc
     *
     * @param string $data
     *
     * @return string
     */
    public function encrypt(string $data): string
    {
        $key = $this->getEncryptionKey();
        if ($key === '' || !function_exists('openssl_encrypt')) {
            return $data;
        }
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = openssl_random_pseudo_bytes($ivLength);
        $cipherText = openssl_encrypt($data, self::CIPHER, $key, \OPENSSL_RAW_DATA, $iv);
        if ($cipherText === \false) {
            return $data;
        }
        return base64_encode($iv . $cipherText);
    }
    /**
     * @inheritDoc
     *
     * @param string $encryptedData
     *
     * @return string
     */
    public function decrypt(string $encryptedData): string
    {
        $key = $this->getEncryptionKey();
        if ($key === '' || !function_exists('openssl_decrypt')) {
            return $encryptedData;
        }
        $decoded = base64_decode($encryptedData, \true);
        if ($decoded === \false) {
            return $encryptedData;
        }
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if (strlen($decoded) <= $ivLength) {
            return $encryptedData;
        }
        $iv = substr($decoded, 0, $ivLength);
        $cipherText = substr($decoded, $ivLength);
        $plainText = openssl_decrypt($cipherText, self::CIPHER, $key, \OPENSSL_RAW_DATA, $iv);
        if ($plainText === \false) {
            return $encryptedData;
        }
        return $plainText;
    }
    /**
     * Derives a 32-byte key from the WordPress installation salts.
     *
     * @return string
     */
    private function getEncryptionKey(): string
    {
        $seed = '';
        if (defined('WOP\AUTH_KEY') && AUTH_KEY !== '') {
            $seed .= AUTH_KEY;
        }
        if (defined('WOP\SECURE_AUTH_KEY') && SECURE_AUTH_KEY !== '') {
            $seed .= SECURE_AUTH_KEY;
        }
        if ($seed === '' && function_exists('wp_salt')) {
            $seed = wp_salt('auth');
        }
        if ($seed === '') {
            return '';
        }
        return hash('sha256', $seed, \true);
    }
}
