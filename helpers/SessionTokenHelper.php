<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

// Load JWT library if available
$jwtAutoloader = __DIR__ . '/../vendor/autoload.php';
if (file_exists($jwtAutoloader)) {
    require_once $jwtAutoloader;
}

class SessionTokenHelper
{
    /**
     * Extract session token from request headers
     * 
     * @return string|null The session token or null if not found
     */
    public static function extractSessionToken(): ?string
    {
        // Try X-Shopify-Session-Token header first (standard for embedded apps)
        $headers = getallheaders();
        
        // Enhanced logging for debugging
        error_log("SessionTokenHelper: Checking headers. Available headers: " . implode(', ', array_keys($headers)));
        
        if (isset($headers['X-Shopify-Session-Token'])) {
            $token = trim($headers['X-Shopify-Session-Token']);
            error_log("SessionTokenHelper: Found X-Shopify-Session-Token header, length: " . strlen($token));
            return $token;
        }
        
        // Also check Authorization header (Bearer token)
        if (isset($headers['Authorization'])) {
            $authHeader = $headers['Authorization'];
            if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
                $token = trim($matches[1]);
                error_log("SessionTokenHelper: Found Authorization Bearer token, length: " . strlen($token));
                return $token;
            }
        }
        
        error_log("SessionTokenHelper: No session token found. Checked X-Shopify-Session-Token and Authorization headers.");
        return null;
    }

    /**
     * Get Shopify public key from JWKS endpoint
     * 
     * @param string $shop The shop domain
     * @return string|null The public key in PEM format or null if not found
     */
    private static function getShopifyPublicKey(string $shop): ?string
    {
        // Cache key for the public key
        $cacheKey = 'shopify_public_key_' . $shop;
        $cacheFile = sys_get_temp_dir() . '/' . $cacheKey . '.pem';
        
        // Check cache (valid for 1 hour)
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            $publicKey = file_get_contents($cacheFile);
            if ($publicKey) {
                error_log("SessionTokenHelper: Using cached public key for shop: {$shop}");
                return $publicKey;
            }
        }

        try {
            // Fetch JWKS from Shopify
            $jwksUrl = "https://{$shop}/.well-known/jwks.json";
            error_log("SessionTokenHelper: Fetching JWKS from: {$jwksUrl}");
            
            $context = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'method' => 'GET',
                ]
            ]);
            
            $jwksJson = @file_get_contents($jwksUrl, false, $context);
            
            if (!$jwksJson) {
                error_log("SessionTokenHelper: Failed to fetch JWKS for shop: {$shop}");
                return null;
            }

            $jwks = json_decode($jwksJson, true);
            if (!$jwks || !isset($jwks['keys']) || empty($jwks['keys'])) {
                error_log("SessionTokenHelper: Invalid JWKS response for shop: {$shop}");
                return null;
            }

            // Use the first key (Shopify typically provides one key)
            $key = $jwks['keys'][0];
            
            // Convert JWK to PEM format
            if (!isset($key['n']) || !isset($key['e'])) {
                error_log("SessionTokenHelper: Invalid JWK format for shop: {$shop}");
                return null;
            }

            // Convert base64url to base64
            $modulus = strtr($key['n'], '-_', '+/');
            $exponent = strtr($key['e'], '-_', '+/');
            
            // Pad base64 strings
            $modulus = str_pad($modulus, ceil(strlen($modulus) / 4) * 4, '=', STR_PAD_RIGHT);
            $exponent = str_pad($exponent, ceil(strlen($exponent) / 4) * 4, '=', STR_PAD_RIGHT);
            
            // Decode to binary
            $modulusBin = base64_decode($modulus);
            $exponentBin = base64_decode($exponent);
            
            // Build RSA public key in PEM format
            $publicKey = self::buildRSAPublicKeyPEM($modulusBin, $exponentBin);
            
            // Cache the public key
            file_put_contents($cacheFile, $publicKey);
            error_log("SessionTokenHelper: Fetched and cached public key for shop: {$shop}");
            
            return $publicKey;

        } catch (\Exception $e) {
            error_log("SessionTokenHelper: Error fetching public key for shop: {$shop}, error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Build RSA public key in PEM format from modulus and exponent
     * 
     * @param string $modulus The RSA modulus (binary)
     * @param string $exponent The RSA exponent (binary)
     * @return string The PEM formatted public key
     */
    private static function buildRSAPublicKeyPEM(string $modulus, string $exponent): string
    {
        // Build ASN.1 structure for RSA public key
        $modulusLength = strlen($modulus);
        $exponentLength = strlen($exponent);
        
        // Ensure modulus starts with null byte if MSB is set
        if (ord($modulus[0]) & 0x80) {
            $modulus = "\x00" . $modulus;
            $modulusLength++;
        }
        
        // Build ASN.1 sequence
        $modulusSeq = "\x02" . self::encodeLength($modulusLength) . $modulus;
        $exponentSeq = "\x02" . self::encodeLength($exponentLength) . $exponent;
        $publicKeySeq = $modulusSeq . $exponentSeq;
        $publicKeySeqLength = strlen($publicKeySeq);
        
        $bitstring = "\x03" . self::encodeLength($publicKeySeqLength + 1) . "\x00" . $publicKeySeq;
        $bitstringLength = strlen($bitstring);
        
        $sequence = "\x30" . self::encodeLength($bitstringLength) . $bitstring;
        
        // Encode to base64 and add PEM headers
        $publicKeyDer = base64_encode($sequence);
        $publicKeyPEM = "-----BEGIN PUBLIC KEY-----\n";
        $publicKeyPEM .= chunk_split($publicKeyDer, 64, "\n");
        $publicKeyPEM .= "-----END PUBLIC KEY-----\n";
        
        return $publicKeyPEM;
    }

    /**
     * Encode length for ASN.1
     * 
     * @param int $length The length to encode
     * @return string The encoded length
     */
    private static function encodeLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        
        $bytes = '';
        $temp = $length;
        while ($temp > 0) {
            $bytes = chr($temp & 0xFF) . $bytes;
            $temp >>= 8;
        }
        
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /**
     * Validate Shopify session token
     * 
     * @param string $token The JWT session token
     * @param string $shop The shop domain to validate against
     * @return array|null Returns decoded payload if valid, null if invalid
     */
    public static function validateSessionToken(string $token, string $shop): ?array
    {
        // Check if JWT library is available
        if (!class_exists('Firebase\JWT\JWT')) {
            error_log("SessionTokenHelper: JWT library not found. Install firebase/php-jwt via Composer.");
            return null;
        }

        try {
            // Decode token without verification first to get header
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                error_log("SessionTokenHelper: Invalid token format for shop: {$shop}");
                return null;
            }

            // Decode header
            $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
            if (!$header || !isset($header['alg'])) {
                error_log("SessionTokenHelper: Invalid token header for shop: {$shop}");
                return null;
            }

            $algorithm = $header['alg'] ?? 'HS256';
            error_log("SessionTokenHelper: Token algorithm: {$algorithm} for shop: {$shop}");

            // Shopify session tokens use RS256, so we need the public key
            if ($algorithm === 'RS256') {
                $publicKey = self::getShopifyPublicKey($shop);
                if (!$publicKey) {
                    error_log("SessionTokenHelper: Failed to get public key for RS256 validation, shop: {$shop}");
                    return null;
                }

                // Decode and verify token with RS256
                $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($publicKey, 'RS256'));
            } else {
                // Fallback to HS256 with API secret (for backward compatibility)
                error_log("SessionTokenHelper: Using HS256 validation with API secret for shop: {$shop}");
                $secret = SHOPIFY_API_SECRET;
                $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($secret, 'HS256'));
            }

            // Convert to array
            $payload = (array) $decoded;

            // Validate shop matches
            $tokenShop = $payload['dest'] ?? null;
            if (!$tokenShop || $tokenShop !== $shop) {
                error_log("SessionTokenHelper: Shop mismatch. Token shop: {$tokenShop}, Request shop: {$shop}");
                return null;
            }

            // Validate API key (aud claim)
            $apiKey = $payload['aud'] ?? null;
            if (!$apiKey || $apiKey !== SHOPIFY_API_KEY) {
                error_log("SessionTokenHelper: API key mismatch for shop: {$shop}. Token API key: {$apiKey}, Expected: " . SHOPIFY_API_KEY);
                return null;
            }

            // Check expiration
            $exp = $payload['exp'] ?? null;
            if ($exp && $exp < time()) {
                error_log("SessionTokenHelper: Token expired for shop: {$shop}. Exp: {$exp}, Now: " . time());
                return null;
            }

            error_log("SessionTokenHelper: Token validated successfully for shop: {$shop}");
            return $payload;

        } catch (\Exception $e) {
            error_log("SessionTokenHelper: Token validation failed for shop: {$shop}, error: " . $e->getMessage());
            error_log("SessionTokenHelper: Exception trace: " . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Validate session token from current request
     * 
     * @param string $shop The shop domain to validate against
     * @return array|null Returns decoded payload if valid, null if invalid or missing
     */
    public static function validateRequest(string $shop): ?array
    {
        $token = self::extractSessionToken();
        
        if (!$token) {
            error_log("SessionTokenHelper: No session token found in request for shop: {$shop}");
            return null;
        }

        return self::validateSessionToken($token, $shop);
    }
}

