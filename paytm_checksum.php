<?php
// Minimal PaytmChecksum implementation (as per official SDK)
// Source adapted from Paytm official PHP checksum utility
class PaytmChecksum {
    private static $iv = '@@@@&&&&####$$$$';

    public static function generateSignature($params, $key) {
        $json = is_string($params) ? $params : json_encode($params, JSON_UNESCAPED_SLASHES);
        return self::generateSignatureByString($json, $key);
    }

    public static function verifySignature($params, $key, $checksum) {
        $json = is_string($params) ? $params : json_encode($params, JSON_UNESCAPED_SLASHES);
        $paytm_hash = self::decrypt($checksum, $key);
        $salt = substr($paytm_hash, -4);
        $finalString = $json . '|' . $salt;
        $website_hash = hash('sha256', $finalString) . $salt;
        return $paytm_hash === $website_hash;
    }

    public static function generateSignatureByString($input, $key) {
        $salt = self::generateRandomString(4);
        $finalString = $input . '|' . $salt;
        $hash = hash('sha256', $finalString);
        return self::encrypt($hash . $salt, $key);
    }

    private static function encrypt($input, $key) {
        $key = html_entity_decode($key);
        if (function_exists('openssl_encrypt')) {
            $data = openssl_encrypt($input, 'AES-128-CBC', $key, OPENSSL_ZERO_PADDING, self::$iv);
        } else {
            throw new Exception('OpenSSL not available for encryption');
        }
        return base64_encode($data);
    }

    private static function decrypt($encrypted, $key) {
        $key = html_entity_decode($key);
        $data = base64_decode($encrypted);
        if (function_exists('openssl_decrypt')) {
            $result = openssl_decrypt($data, 'AES-128-CBC', $key, OPENSSL_ZERO_PADDING, self::$iv);
        } else {
            throw new Exception('OpenSSL not available for decryption');
        }
        return $result;
    }

    private static function generateRandomString($length) {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }
}
