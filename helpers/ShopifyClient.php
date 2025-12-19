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
            $tokenLength = strlen($accessToken);
            error_log("Successfully obtained access token for shop: {$shop}, length: {$tokenLength}");
            error_log("Token preview (first 10, last 10): " . substr($accessToken, 0, 10) . "..." . substr($accessToken, -10));
            
            // Validate token length from Shopify response (minimum 38 chars is valid)
            if ($tokenLength < 38) {
                error_log("WARNING: Token from Shopify is shorter than expected for shop: {$shop}. Length: {$tokenLength}, expected at least 38 characters.");
            } elseif ($tokenLength === 38) {
                // 38 chars is valid: shpat_ (6) + 32 chars = 38 total
                error_log("Token length is 38 characters (valid format: shpat_ + 32 chars)");
            }
        } else {
            error_log("Access token not found in response for shop: {$shop}. Response: " . substr($response['body'], 0, 200));
            // Log full response body for debugging (truncated)
            if (!empty($response['body'])) {
                $responsePreview = json_decode($response['body'], true);
                error_log("Response keys: " . implode(', ', array_keys($responsePreview ?? [])));
            }
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
        
        // If 401 and retry enabled, retry multiple times (for token activation delays on fresh installs)
        if ($response['status'] === 401 && $retryOn401) {
            $maxRetries = 5;
            $retryDelay = 2; // seconds between retries
            $retryCount = 0;
            
            error_log("401 error on {$apiPath} for shop: {$shop}, retryOn401=true - starting retry loop (max {$maxRetries} retries, {$retryDelay}s delay)");
            
            while ($response['status'] === 401 && $retryCount < $maxRetries) {
                $retryCount++;
                error_log("Retry attempt {$retryCount}/{$maxRetries} for shop: {$shop}, waiting {$retryDelay}s before retry...");
                sleep($retryDelay);
                
                $response = self::curl($url, $method, $data, $headers);
                $decoded = json_decode($response['body'], true);
                
                if ($response['status'] === 200) {
                    error_log("✓ Retry SUCCESSFUL for shop: {$shop} on attempt {$retryCount}/{$maxRetries}");
                    break;
                } else {
                    error_log("✗ Retry attempt {$retryCount}/{$maxRetries} FAILED for shop: {$shop}, status: {$response['status']}");
                    if ($response['status'] !== 401) {
                        error_log("  Non-401 error on retry, stopping retry loop");
                        break;
                    }
                }
            }
            
            if ($response['status'] === 200) {
                error_log("Final result: SUCCESS after {$retryCount} retry attempt(s) for shop: {$shop}");
            } else {
                error_log("Final result: FAILED after {$retryCount} retry attempt(s) for shop: {$shop}, final status: {$response['status']}");
            }
        } elseif ($response['status'] === 401 && !$retryOn401) {
            error_log("401 error on {$apiPath} for shop: {$shop}, but retryOn401=false - no retries will be attempted");
        }
        
        return [
            'status' => $response['status'],
            'body'   => $decoded,
            'raw'    => $response['body'],
        ];
    }

    /**
     * Create a recurring application charge using GraphQL (required for public apps)
     */
    public static function createRecurringCharge(string $shop, string $accessToken, float $amount, string $planType): array
    {
        $name = $planType === 'annual' ? 'Annual Subscription' : 'Monthly Subscription';
        $interval = $planType === 'annual' ? 'ANNUAL' : 'EVERY_30_DAYS';
        $returnUrl = 'https://' . parse_url(SHOPIFY_REDIRECT_URI, PHP_URL_HOST) . '/subscription.php?shop=' . urlencode($shop);
        
        // GraphQL mutation for creating app subscription
        $mutation = <<<GRAPHQL
mutation appSubscriptionCreate(\$name: String!, \$lineItems: [AppSubscriptionLineItemInput!]!, \$returnUrl: URL!, \$test: Boolean) {
  appSubscriptionCreate(
    name: \$name
    lineItems: \$lineItems
    returnUrl: \$returnUrl
    test: \$test
  ) {
    appSubscription {
      id
      status
      name
      currentPeriodEnd
    }
    confirmationUrl
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

        $variables = [
            'name' => $name,
            'lineItems' => [
                [
                    'plan' => [
                        'appRecurringPricingDetails' => [
                            'price' => [
                                'amount' => $amount,
                                'currencyCode' => 'USD'
                            ],
                            'interval' => $interval
                        ]
                    ]
                ]
            ],
            'returnUrl' => $returnUrl,
            'test' => false // Set to true for development stores
        ];

        $response = self::graphqlQuery($shop, $accessToken, $mutation, $variables);
        
        // Transform GraphQL response to match expected format
        if ($response['status'] === 200 && isset($response['body']['data']['appSubscriptionCreate'])) {
            $result = $response['body']['data']['appSubscriptionCreate'];
            
            // Check for user errors
            if (!empty($result['userErrors'])) {
                error_log("GraphQL user errors: " . json_encode($result['userErrors']));
                return [
                    'status' => 400,
                    'body' => ['errors' => $result['userErrors']],
                    'raw' => json_encode($result['userErrors'])
                ];
            }
            
            // Return in format similar to REST API for compatibility
            return [
                'status' => 201,
                'body' => [
                    'recurring_application_charge' => [
                        'id' => $result['appSubscription']['id'] ?? null,
                        'name' => $result['appSubscription']['name'] ?? $name,
                        'status' => $result['appSubscription']['status'] ?? 'pending',
                        'confirmation_url' => $result['confirmationUrl'] ?? null
                    ]
                ],
                'raw' => $response['raw']
            ];
        }
        
        // Return error response
        return $response;
    }

    /**
     * Get the status of a charge using GraphQL
     */
    public static function getChargeStatus(string $shop, string $accessToken, string $chargeId): array
    {
        // Extract the GID if it's not already in GID format
        $gid = $chargeId;
        if (!str_starts_with($chargeId, 'gid://')) {
            $gid = "gid://shopify/AppSubscription/{$chargeId}";
        }
        
        $query = <<<GRAPHQL
query getAppSubscription(\$id: ID!) {
  appSubscription(id: \$id) {
    id
    name
    status
    currentPeriodEnd
    confirmationUrl
    lineItems {
      id
      plan {
        ... on AppRecurringPricing {
          price {
            amount
            currencyCode
          }
          interval
        }
      }
    }
  }
}
GRAPHQL;

        $variables = ['id' => $gid];
        return self::graphqlQuery($shop, $accessToken, $query, $variables);
    }

    /**
     * Cancel a recurring charge using GraphQL
     */
    public static function cancelCharge(string $shop, string $accessToken, string $chargeId): array
    {
        // Extract the GID if it's not already in GID format
        $gid = $chargeId;
        if (!str_starts_with($chargeId, 'gid://')) {
            $gid = "gid://shopify/AppSubscription/{$chargeId}";
        }
        
        $mutation = <<<GRAPHQL
mutation appSubscriptionCancel(\$id: ID!) {
  appSubscriptionCancel(id: \$id) {
    appSubscription {
      id
      status
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

        $variables = ['id' => $gid];
        $response = self::graphqlQuery($shop, $accessToken, $mutation, $variables);
        
        // Check for user errors
        if ($response['status'] === 200 && isset($response['body']['data']['appSubscriptionCancel'])) {
            $result = $response['body']['data']['appSubscriptionCancel'];
            
            if (!empty($result['userErrors'])) {
                error_log("GraphQL cancel user errors: " . json_encode($result['userErrors']));
                return [
                    'status' => 400,
                    'body' => ['errors' => $result['userErrors']],
                    'raw' => json_encode($result['userErrors'])
                ];
            }
            
            // Return success response
            return [
                'status' => 200,
                'body' => $result,
                'raw' => $response['raw']
            ];
        }
        
        return $response;
    }

    /**
     * Create a webhook using GraphQL (for GraphQL-only topics like app_subscriptions/update)
     */
    public static function createWebhookGraphQL(string $shop, string $accessToken, string $topic, string $address): array
    {
        // Convert REST topic format to GraphQL enum format
        $graphqlTopic = strtoupper(str_replace('/', '_', $topic));
        
        $mutation = <<<GRAPHQL
mutation webhookSubscriptionCreate(\$topic: WebhookSubscriptionTopic!, \$webhookSubscription: WebhookSubscriptionInput!) {
  webhookSubscriptionCreate(topic: \$topic, webhookSubscription: \$webhookSubscription) {
    webhookSubscription {
      id
      callbackUrl
      format
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

        $variables = [
            'topic' => $graphqlTopic,
            'webhookSubscription' => [
                'callbackUrl' => $address,
                'format' => 'JSON'
            ]
        ];

        $response = self::graphqlQuery($shop, $accessToken, $mutation, $variables);
        
        // Transform to match REST API response format for compatibility
        if ($response['status'] === 200 && isset($response['body']['data']['webhookSubscriptionCreate'])) {
            $result = $response['body']['data']['webhookSubscriptionCreate'];
            
            if (!empty($result['userErrors'])) {
                return [
                    'status' => 400,
                    'body' => ['errors' => $result['userErrors']],
                    'raw' => json_encode($result['userErrors'])
                ];
            }
            
            return [
                'status' => 201,
                'body' => ['webhook' => $result['webhookSubscription']],
                'raw' => $response['raw']
            ];
        }
        
        return $response;
    }

    /**
     * Create a webhook for the shop (REST API - for backward compatibility)
     */
    public static function createWebhook(string $shop, string $accessToken, string $topic, string $address): array
    {
        $payload = [
            'webhook' => [
                'topic'   => $topic,
                'address' => $address,
                'format'  => 'json',
            ],
        ];
        
        return self::apiRequest($shop, $accessToken, '/admin/api/2024-10/webhooks.json', 'POST', $payload);
    }

    /**
     * List existing webhooks for a shop
     */
    public static function listWebhooks(string $shop, string $accessToken): array
    {
        return self::apiRequest($shop, $accessToken, '/admin/api/2024-10/webhooks.json', 'GET');
    }

    /**
     * Delete a webhook
     */
    public static function deleteWebhook(string $shop, string $accessToken, string $webhookId): array
    {
        return self::apiRequest($shop, $accessToken, "/admin/api/2024-10/webhooks/{$webhookId}.json", 'DELETE');
    }

    /**
     * Execute a GraphQL query
     */
    public static function graphqlQuery(string $shop, string $accessToken, string $query, array $variables = []): array
    {
        $url = "https://{$shop}/admin/api/2024-10/graphql.json";

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
