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
        if (isset($headers['X-Shopify-Session-Token'])) {
            return trim($headers['X-Shopify-Session-Token']);
        }
        
        // Also check Authorization header (Bearer token)
        if (isset($headers['Authorization'])) {
            $authHeader = $headers['Authorization'];
            if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
                return trim($matches[1]);
            }
        }
        
        return null;
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

            // Use API secret for HS256 validation
            $secret = SHOPIFY_API_SECRET;
            $algorithm = $header['alg'] ?? 'HS256';

            // Decode and verify token
            $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($secret, $algorithm));

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
                error_log("SessionTokenHelper: API key mismatch for shop: {$shop}");
                return null;
            }

            // Check expiration
            $exp = $payload['exp'] ?? null;
            if ($exp && $exp < time()) {
                error_log("SessionTokenHelper: Token expired for shop: {$shop}");
                return null;
            }

            error_log("SessionTokenHelper: Token validated successfully for shop: {$shop}");
            return $payload;

        } catch (\Exception $e) {
            error_log("SessionTokenHelper: Token validation failed for shop: {$shop}, error: " . $e->getMessage());
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

