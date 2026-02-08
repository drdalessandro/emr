<?php

/**
 * Helper for generating JWT tokens for Jitsi Meet authentication.
 *
 * @package   openemr
 * @link      http://www.open-emr.org
 * @author    EPA Bienestar
 * @copyright Copyright (c) 2024 EPA Bienestar
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace EPA\OpenEMR\Modules\JitsiTeleHealth\Util;

class JitsiJwtHelper
{
    /**
     * Generate a JWT token for Jitsi Meet authentication.
     *
     * @param string $appId     The application ID configured on the Jitsi server
     * @param string $appSecret The shared secret for signing tokens
     * @param string $roomName  The Jitsi room name
     * @param string $userName  Display name for the user
     * @param string $email     Email of the user
     * @param bool   $moderator Whether the user should be a moderator
     * @param int    $ttl       Token time-to-live in seconds (default: 2 hours)
     * @return string The signed JWT token
     */
    public static function generateToken(
        string $appId,
        string $appSecret,
        string $roomName,
        string $userName,
        string $email = '',
        bool $moderator = false,
        int $ttl = 7200
    ): string {
        $now = time();

        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256'
        ];

        $payload = [
            'iss' => $appId,
            'sub' => '*',
            'aud' => 'jitsi',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'room' => $roomName,
            'context' => [
                'user' => [
                    'name' => $userName,
                    'email' => $email,
                    'moderator' => $moderator ? 'true' : 'false',
                    'affiliation' => $moderator ? 'owner' : 'member',
                ],
                'features' => [
                    'recording' => $moderator ? 'true' : 'false',
                    'livestreaming' => 'false',
                    'screen-sharing' => 'true',
                ]
            ]
        ];

        $headerEncoded = self::base64UrlEncode(json_encode($header));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));

        $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $appSecret, true);
        $signatureEncoded = self::base64UrlEncode($signature);

        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }

    /**
     * Base64 URL-safe encoding.
     */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
