<?php

namespace App\Helpers;

class Google2FA
{
    private static $base32LookupTable = [
        'A' => 0,  'B' => 1,  'C' => 2,  'D' => 3,
        'E' => 4,  'F' => 5,  'G' => 6,  'H' => 7,
        'I' => 8,  'J' => 9,  'K' => 10, 'L' => 11,
        'M' => 12, 'N' => 13, 'O' => 14, 'P' => 15,
        'Q' => 16, 'R' => 17, 'S' => 18, 'T' => 19,
        'U' => 20, 'V' => 21, 'W' => 22, 'X' => 23,
        'Y' => 24, 'Z' => 25, '2' => 26, '3' => 27,
        '4' => 28, '5' => 29, '6' => 30, '7' => 31
    ];

    /**
     * Generate a random 16-character Base32 secret.
     */
    public static function generateSecretKey($length = 16)
    {
        $keys = array_keys(self::$base32LookupTable);
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $keys[random_int(0, 31)];
        }
        return $secret;
    }

    /**
     * Get QR Code URL.
     */
    public static function getQRCodeUrl($name, $secret, $holder = 'LibrarySystem')
    {
        return 'otpauth://totp/' . rawurlencode($holder . ':' . $name) . '?secret=' . $secret . '&issuer=' . rawurlencode($holder);
    }

    /**
     * Decode a base32 string.
     */
    private static function base32Decode($secret)
    {
        if (empty($secret)) {
            return '';
        }

        $secret = strtoupper($secret);
        $secret = str_replace('=', '', $secret);
        $allowedValues = array_keys(self::$base32LookupTable);
        
        for ($i = 0; $i < strlen($secret); $i++) {
            if (!in_array($secret[$i], $allowedValues)) {
                return false;
            }
        }

        $buf = '';
        $val = 0;
        $vLen = 0;

        for ($i = 0; $i < strlen($secret); $i++) {
            $val = ($val << 5) | self::$base32LookupTable[$secret[$i]];
            $vLen += 5;
            if ($vLen >= 8) {
                $buf .= chr(($val >> ($vLen - 8)) & 0xFF);
                $vLen -= 8;
            }
        }

        return $buf;
    }

    /**
     * Calculate the code for a secret key at a given time slice.
     */
    public static function getCode($secret, $timeSlice = null)
    {
        if ($timeSlice === null) {
            $timeSlice = floor(time() / 30);
        }

        $secretkey = self::base32Decode($secret);
        if ($secretkey === false) {
            return false;
        }

        // Pack time into binary 64-bit string
        $time = chr(0).chr(0).chr(0).chr(0).pack('N*', $timeSlice);
        
        // Hash it with HMAC-SHA1
        $hm = hash_hmac('sha1', $time, $secretkey, true);
        
        // Use last nibble of result as offset
        $offset = ord(substr($hm, -1)) & 0x0F;
        
        // Grab 4 bytes of the result
        $hashpart = substr($hm, $offset, 4);
        
        // Unpack binary value
        $value = unpack('N', $hashpart);
        $value = $value[1];
        
        // Only keep 31 bits
        $value = $value & 0x7FFFFFFF;
        
        // Modulo 1,000,000 to get a 6-digit code
        $modulo = pow(10, 6);
        $code = str_pad($value % $modulo, 6, '0', STR_PAD_LEFT);
        
        return $code;
    }

    /**
     * Verify a code.
     * Checks 1 code back and 1 code forward to handle clock drift (window = 1).
     */
    public static function verifyCode($secret, $code, $window = 1)
    {
        $currentTimeSlice = floor(time() / 30);
        
        for ($i = -$window; $i <= $window; $i++) {
            $calculatedCode = self::getCode($secret, $currentTimeSlice + $i);
            if ($calculatedCode === $code) {
                return true;
            }
        }
        
        return false;
    }
}
