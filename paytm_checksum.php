<?php
// Minimal PaytmChecksum implementation (compatible with classic flow)
class PaytmChecksum {
    private static $iv = '@@@@&&&&####$$$$';

    public static function generateSignature($params, $key) {
        if (!is_string($params)) {
            if (isset($params['CHECKSUMHASH'])) { unset($params['CHECKSUMHASH']); }
            ksort($params);
            $params = json_encode($params, JSON_UNESCAPED_SLASHES);
        }
        return self::generateSignatureByString($params, $key);
    }

    public static function verifySignature($params, $key, $checksum) {
        if (!is_string($params)) {
            if (isset($params['CHECKSUMHASH'])) { unset($params['CHECKSUMHASH']); }
            ksort($params);
            $params = json_encode($params, JSON_UNESCAPED_SLASHES);
        }
        $paytm_hash = self::decrypt($checksum, $key);
        $salt = substr($paytm_hash, -4);
        $finalString = $params . '|' . $salt;
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
        $data = openssl_encrypt($input, 'AES-128-CBC', $key, OPENSSL_ZERO_PADDING, self::$iv);
        return base64_encode($data);
    }

    private static function decrypt($encrypted, $key) {
        $key = html_entity_decode($key);
        $data = base64_decode($encrypted);
        return openssl_decrypt($data, 'AES-128-CBC', $key, OPENSSL_ZERO_PADDING, self::$iv);
    }

    private static function generateRandomString($length) {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $randomString;
    }
}
