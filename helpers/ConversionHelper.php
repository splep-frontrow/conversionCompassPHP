<?php
require_once __DIR__ . '/ShopifyClient.php';

class ConversionHelper
{
    /**
     * Get orders with conversion data for a date range
     */
    public static function getOrdersWithConversionData(string $shop, string $accessToken, string $startDate, string $endDate): array
    {
        $orders = [];
        $hasNextPage = true;
        $cursor = null;

        while ($hasNextPage) {
            $query = self::buildOrdersQuery();
            $variables = [
                'first' => 50,
                'query' => "financial_status:paid created_at:>={$startDate} created_at:<={$endDate}",
                'after' => $cursor,
            ];

            $response = ShopifyClient::graphqlQuery($shop, $accessToken, $query, $variables);

            if ($response['status'] !== 200) {
                break;
            }

            $data = $response['body']['data'] ?? null;
            if (!$data || !isset($data['orders'])) {
                break;
            }

            $ordersData = $data['orders'];
            $edges = $ordersData['edges'] ?? [];

            foreach ($edges as $edge) {
                $order = $edge['node'] ?? null;
                if ($order) {
                    $orders[] = $order;
                }
            }

            $pageInfo = $ordersData['pageInfo'] ?? [];
            $hasNextPage = $pageInfo['hasNextPage'] ?? false;
            $cursor = $hasNextPage ? ($pageInfo['endCursor'] ?? null) : null;
        }

        return $orders;
    }

    /**
     * Build GraphQL query for orders with conversion data
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
        customerJourneySummary {
          firstVisit {
            landingPage
            referrerUrl
            utmSource
            utmMedium
            utmCampaign
            utmContent
          }
          lastVisit {
            landingPage
            referrerUrl
            utmSource
            utmMedium
            utmCampaign
            utmContent
          }
          momentsCount
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
        // Convert Y-m-d to ISO 8601 format
        $timestamp = strtotime($date);
        return date('Y-m-d\TH:i:s\Z', $timestamp);
    }
}

