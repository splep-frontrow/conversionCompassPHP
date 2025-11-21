<?php
require_once __DIR__ . '/../config.php';

class ShopifyClient
{
    public static function getAccessToken(string $shop, string $code): ?string
    {
        $url = "https://{$shop}/admin/oauth/access_token";

        $payload = [
            'client_id'     => SHOPIFY_API_KEY,
            'client_secret' => SHOPIFY_API_SECRET,
            'code'          => $code,
        ];

        $response = self::curl($url, 'POST', $payload, [
            'Content-Type: application/json',
        ]);

        if ($response['status'] !== 200) {
            // Log error details for debugging
            $errorBody = json_decode($response['body'], true);
            $errorMsg = $errorBody['error_description'] ?? $errorBody['error'] ?? $response['body'];
            error_log("Failed to get access token for shop {$shop}: HTTP {$response['status']} - {$errorMsg}");
            return null;
        }

        $body = json_decode($response['body'], true);
        $accessToken = $body['access_token'] ?? null;
        
        if ($accessToken) {
            // Trim whitespace from token
            $accessToken = trim($accessToken);
            error_log("Successfully obtained access token for shop: {$shop}, length: " . strlen($accessToken));
        } else {
            error_log("Access token not found in response for shop: {$shop}. Response: " . substr($response['body'], 0, 200));
        }
        
        return $accessToken;
    }

    public static function apiRequest(string $shop, string $accessToken, string $path, string $method = 'GET', ?array $data = null, bool $retryOn401 = false): array
    {
        // Trim token to ensure no whitespace issues
        $accessToken = trim($accessToken);
        
        if (empty($accessToken)) {
            error_log("ERROR: Empty access token provided for API request to shop: {$shop}, path: {$path}");
            return [
                'status' => 401,
                'body'   => ['error' => 'Access token is empty'],
                'raw'    => 'Access token is empty',
            ];
        }
        
        // Update API version to latest stable (2024-10)
        // Replace old API version in path if present
        $apiPath = $path;
        if (preg_match('#/admin/api/\d{4}-\d{2}/#', $apiPath)) {
            // Use 2024-10 (latest stable as of Nov 2024)
            $apiPath = preg_replace('#/admin/api/\d{4}-\d{2}/#', '/admin/api/2024-10/', $apiPath);
        }
        
        $url = "https://{$shop}{$apiPath}";

        $headers = [
            'X-Shopify-Access-Token: ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $response = self::curl($url, $method, $data, $headers);

        $decoded = json_decode($response['body'], true);
        
        // Log API errors for debugging
        if ($response['status'] !== 200) {
            error_log("Shopify API Error - Shop: {$shop}, Status: {$response['status']}, Path: {$apiPath}, Original Path: {$path}, Response: " . substr($response['body'], 0, 500));
        }
        
        // If 401 and retry enabled, wait and retry once (for token activation delays)
        if ($response['status'] === 401 && $retryOn401) {
            error_log("401 error on {$apiPath}, waiting 2 seconds and retrying...");
            sleep(2);
            
            $response = self::curl($url, $method, $data, $headers);
            $decoded = json_decode($response['body'], true);
            
            if ($response['status'] === 200) {
                error_log("Retry successful for shop: {$shop} after delay");
            } else {
                error_log("Retry still failed for shop: {$shop}, status: {$response['status']}");
            }
        }
        
        return [
            'status' => $response['status'],
            'body'   => $decoded,
            'raw'    => $response['body'],
        ];
    }

    /**
     * Create a recurring application charge
     */
    public static function createRecurringCharge(string $shop, string $accessToken, float $amount, string $planType): array
    {
        $name = $planType === 'annual' ? 'Annual Subscription' : 'Monthly Subscription';
        
        $payload = [
            'recurring_application_charge' => [
                'name'       => $name,
                'price'      => $amount,
                'return_url' => 'https://' . parse_url(SHOPIFY_REDIRECT_URI, PHP_URL_HOST) . '/subscription.php?shop=' . urlencode($shop),
                'test'       => false, // Set to true for development stores
            ],
        ];

        return self::apiRequest($shop, $accessToken, '/admin/api/2024-01/recurring_application_charges.json', 'POST', $payload);
    }

    /**
     * Get the status of a charge
     */
    public static function getChargeStatus(string $shop, string $accessToken, string $chargeId): array
    {
        return self::apiRequest($shop, $accessToken, "/admin/api/2024-01/recurring_application_charges/{$chargeId}.json", 'GET');
    }

    /**
     * Cancel a recurring charge
     */
    public static function cancelCharge(string $shop, string $accessToken, string $chargeId): array
    {
        return self::apiRequest($shop, $accessToken, "/admin/api/2024-01/recurring_application_charges/{$chargeId}.json", 'DELETE');
    }

    /**
     * Execute a GraphQL query
     */
    public static function graphqlQuery(string $shop, string $accessToken, string $query, array $variables = []): array
    {
        $url = "https://{$shop}/admin/api/2024-01/graphql.json";

        $payload = [
            'query' => $query,
        ];

        if (!empty($variables)) {
            $payload['variables'] = $variables;
        }

        $headers = [
            'X-Shopify-Access-Token: ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $response = self::curl($url, 'POST', $payload, $headers);

        $decoded = json_decode($response['body'], true);
        return [
            'status' => $response['status'],
            'body'   => $decoded,
            'raw'    => $response['body'],
        ];
    }

    private static function curl(string $url, string $method = 'GET', ?array $data = null, array $headers = []): array
    {
        $ch = curl_init();

        if ($method === 'GET' && !empty($data)) {
            $query = http_build_query($data);
            $url   = $url . (str_contains($url, '?') ? '&' : '?') . $query;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        if ($method === 'POST' || $method === 'PUT' || $method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return [
                'status' => 0,
                'body'   => json_encode(['error' => $error]),
            ];
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $header = substr($response, 0, $headerSize);
        $body   = substr($response, $headerSize);

        curl_close($ch);

        return [
            'status' => $statusCode,
            'header' => $header,
            'body'   => $body,
        ];
    }
}
