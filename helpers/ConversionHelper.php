<?php
require_once __DIR__ . '/ShopifyClient.php';

class ConversionHelper
{
    /**
     * Verify app has required scopes
     */
    public static function verifyScopes(string $shop, string $accessToken): array
    {
        $query = <<<GRAPHQL
query VerifyScopes {
  currentAppInstallation {
    accessScopes {
      handle
    }
  }
}
GRAPHQL;
        
        $response = ShopifyClient::graphqlQuery($shop, $accessToken, $query, []);
        
        if ($response['status'] === 200 && isset($response['body']['data']['currentAppInstallation']['accessScopes'])) {
            $scopes = array_map(function($scope) {
                return $scope['handle'];
            }, $response['body']['data']['currentAppInstallation']['accessScopes']);
            error_log("Granted scopes: " . implode(', ', $scopes));
            return $scopes;
        }
        
        error_log("Failed to verify scopes. Response: " . json_encode($response['body'] ?? []));
        return [];
    }

    /**
     * Get orders with conversion data for a date range
     */
    public static function getOrdersWithConversionData(string $shop, string $accessToken, string $startDate, string $endDate): array
    {
        // First verify scopes
        $scopes = self::verifyScopes($shop, $accessToken);
        if (!in_array('read_orders', $scopes)) {
            error_log("WARNING: read_orders scope not found in granted scopes!");
        }
        
        // Try different query formats
        $queryStrings = [
            // Format 1: Full ISO 8601 with T and Z
            "financial_status:paid created_at:>={$startDate} created_at:<={$endDate}",
            // Format 2: Date only (no time)
            "financial_status:paid created_at:>=" . substr($startDate, 0, 10) . " created_at:<=" . substr($endDate, 0, 10),
            // Format 3: Without date filters (fallback)
            "financial_status:paid",
        ];
        
        foreach ($queryStrings as $index => $queryString) {
            error_log("Trying query format " . ($index + 1) . ": {$queryString}");
            
            $orders = [];
            $hasNextPage = true;
            $cursor = null;
            $query = self::buildOrdersQuery();
            
            while ($hasNextPage) {
                $variables = [
                    'first' => 50,
                    'query' => $queryString,
                    'after' => $cursor,
                ];
                
                error_log("GraphQL query variables: " . json_encode($variables));

                $response = ShopifyClient::graphqlQuery($shop, $accessToken, $query, $variables);

                if ($response['status'] !== 200) {
                    error_log("GraphQL query failed: HTTP {$response['status']}, Response: " . substr($response['raw'] ?? '', 0, 500));
                    break 2; // Break out of both loops
                }

                // Check for GraphQL errors
                if (isset($response['body']['errors'])) {
                    $errorMessages = array_map(function($error) {
                        return $error['message'] ?? 'Unknown error';
                    }, $response['body']['errors']);
                    $errorString = implode(', ', $errorMessages);
                    error_log("GraphQL errors: " . $errorString);
                    
                    // Check for access denied errors - likely scope issue
                    if (str_contains($errorString, 'Access denied') || str_contains($errorString, 'access denied')) {
                        error_log("ACCESS DENIED ERROR: App may need to be reinstalled with read_orders scope. Current scopes in config: " . SHOPIFY_SCOPES);
                        return []; // Return empty, can't proceed
                    }
                    
                    // If date filter error and not last attempt, try next format
                    if ($index < count($queryStrings) - 1) {
                        break; // Try next query format
                    }
                    return []; // All formats failed
                }

                $data = $response['body']['data'] ?? null;
                if (!$data || !isset($data['orders'])) {
                    error_log("No orders data in response. Response keys: " . implode(', ', array_keys($response['body'] ?? [])));
                    if ($index < count($queryStrings) - 1) {
                        break; // Try next format
                    }
                    return [];
                }

                $ordersData = $data['orders'];
                $edges = $ordersData['edges'] ?? [];

                error_log("Found " . count($edges) . " orders in this page");

                foreach ($edges as $edge) {
                    $order = $edge['node'] ?? null;
                    if ($order) {
                        $orders[] = $order;
                        // Log first order structure for debugging
                        if (count($orders) === 1) {
                            error_log("First order structure: " . json_encode(array_keys($order)));
                            error_log("First order name: " . ($order['name'] ?? 'N/A'));
                            error_log("First order createdAt: " . ($order['createdAt'] ?? 'N/A'));
                        }
                    }
                }

                $pageInfo = $ordersData['pageInfo'] ?? [];
                $hasNextPage = $pageInfo['hasNextPage'] ?? false;
                $cursor = $hasNextPage ? ($pageInfo['endCursor'] ?? null) : null;
            }
            
            // If we got orders, return them (even if from fallback query without dates)
            if (!empty($orders)) {
                error_log("Total orders retrieved: " . count($orders) . " using query format " . ($index + 1));
                return $orders;
            }
        }

        error_log("No orders found with any query format");
        return [];
    }

    /**
     * Build GraphQL query for orders with conversion data
     * First version: Simple query to get basic order data
     */
    private static function buildOrdersQuery(): string
    {
        return <<<GRAPHQL
query GetOrders(\$first: Int!, \$query: String!, \$after: String) {
  orders(first: \$first, query: \$query, after: \$after) {
    edges {
      node {
        id
        name
        createdAt
        totalPriceSet {
          shopMoney {
            amount
            currencyCode
          }
        }
      }
    }
    pageInfo {
      hasNextPage
      endCursor
    }
  }
}
GRAPHQL;
    }
    
    /**
     * Build GraphQL query for orders WITH conversion data
     * This will be used once we verify the correct field structure
     */
    private static function buildOrdersQueryWithConversion(): string
    {
        // This will be implemented once we know the correct schema structure
        return self::buildOrdersQuery();
    }

    /**
     * Extract conversion data from order
     */
    public static function extractConversionData(array $order): array
    {
        $journey = $order['customerJourneySummary'] ?? null;
        
        if (!$journey) {
            return [
                'campaign' => 'N/A',
                'source' => 'N/A',
                'medium' => 'N/A',
                'content' => 'N/A',
                'referring_site' => 'N/A',
            ];
        }

        // Prefer last visit data (most recent), fallback to first visit
        $lastVisit = $journey['lastVisit'] ?? null;
        $firstVisit = $journey['firstVisit'] ?? null;
        $visit = $lastVisit ?: $firstVisit;

        if (!$visit) {
            return [
                'campaign' => 'N/A',
                'source' => 'N/A',
                'medium' => 'N/A',
                'content' => 'N/A',
                'referring_site' => 'N/A',
            ];
        }

        // Extract referrer URL - prefer referrerUrl, fallback to landingPage
        $referrerUrl = $visit['referrerUrl'] ?? $visit['landingPage'] ?? null;
        $referringSite = 'N/A';
        if ($referrerUrl) {
            $parsed = parse_url($referrerUrl);
            $referringSite = $parsed['host'] ?? $referrerUrl;
        }

        // Extract UTM parameters - try direct fields first (camelCase)
        // Shopify GraphQL uses camelCase for field names
        return [
            'campaign' => $visit['utmCampaign'] ?? 'N/A',
            'source' => $visit['utmSource'] ?? 'N/A',
            'medium' => $visit['utmMedium'] ?? 'N/A',
            'content' => $visit['utmContent'] ?? 'N/A',
            'referring_site' => $referringSite,
        ];
    }

    /**
     * Categorize referrer based on UTM parameters
     */
    public static function categorizeReferrer(string $source, string $medium): string
    {
        $sourceLower = strtolower($source);
        $mediumLower = strtolower($medium);

        // Social Media
        $socialSources = ['facebook', 'instagram', 'twitter', 'linkedin', 'pinterest', 'tiktok', 'snapchat', 'x.com', 'youtube'];
        foreach ($socialSources as $social) {
            if (str_contains($sourceLower, $social)) {
                return 'Social Media';
            }
        }
        if ($mediumLower === 'social') {
            return 'Social Media';
        }

        // Email
        if ($mediumLower === 'email' || str_contains($sourceLower, 'mail') || str_contains($sourceLower, 'newsletter')) {
            return 'Email';
        }

        // Direct Links
        if ($sourceLower === 'direct' || $mediumLower === 'none' || $sourceLower === 'n/a' || $mediumLower === 'n/a') {
            return 'Direct Links';
        }

        // Other
        return 'Other';
    }

    /**
     * Calculate statistics from orders
     */
    public static function calculateStatistics(array $orders): array
    {
        $totalOrders = count($orders);
        $totalRevenue = 0.0;
        $referrerCategories = [
            'Social Media' => 0,
            'Direct Links' => 0,
            'Email' => 0,
            'Other' => 0,
        ];

        foreach ($orders as $order) {
            // Calculate revenue
            $totalPriceSet = $order['totalPriceSet'] ?? null;
            if ($totalPriceSet && isset($totalPriceSet['shopMoney']['amount'])) {
                $totalRevenue += (float)$totalPriceSet['shopMoney']['amount'];
            }

            // Categorize referrer
            $conversionData = self::extractConversionData($order);
            $category = self::categorizeReferrer($conversionData['source'], $conversionData['medium']);
            $referrerCategories[$category]++;
        }

        return [
            'total_orders' => $totalOrders,
            'total_revenue' => $totalRevenue,
            'referrer_summary' => $referrerCategories,
        ];
    }

    /**
     * Format date for Shopify GraphQL query (ISO 8601)
     */
    public static function formatDateForQuery(string $date): string
    {
        // Convert various date formats to ISO 8601 format
        // Accepts: Y-m-d, Y-m-d H:i:s, or already formatted dates
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            error_log("Invalid date format: {$date}");
            return date('Y-m-d\TH:i:s\Z');
        }
        return date('Y-m-d\TH:i:s\Z', $timestamp);
    }

    /**
     * Generate CSV content from order data
     * 
     * @param array $orderData Array of order data arrays
     * @return string CSV content
     */
    public static function generateCSV(array $orderData): string
    {
        if (empty($orderData)) {
            return '';
        }

        // Open output buffer for CSV generation
        $output = fopen('php://temp', 'r+');

        // CSV Headers
        $headers = [
            'Order Number',
            'Order Date',
            'Total Amount',
            'Currency',
            'Campaign',
            'Source',
            'Medium',
            'Referring Site',
            'Category',
            'Order URL',
        ];
        fputcsv($output, $headers);

        // Add data rows
        foreach ($orderData as $order) {
            $row = [
                $order['number'] ?? '',
                $order['date'] ?? '',
                $order['total_amount'] ?? 0,
                $order['currency'] ?? '',
                $order['campaign'] ?? 'N/A',
                $order['source'] ?? 'N/A',
                $order['medium'] ?? 'N/A',
                $order['referring_site'] ?? 'N/A',
                $order['category'] ?? 'Other',
                $order['url'] ?? '',
            ];
            fputcsv($output, $row);
        }

        // Get CSV content
        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        return $csvContent;
    }
}

