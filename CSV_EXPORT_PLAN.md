# CSV Export Feature - Implementation Plan

## Overview
Add a CSV export feature that allows users to export order conversion data after running a query. The export button will only be enabled/visible when there are results to export.

## Current State Analysis

### Data Structure
The order data is stored in `$orderData` array in `conversion.php` with the following fields:
- `id` - Order ID (Shopify GraphQL ID)
- `name` - Order name (e.g., "Order #1001")
- `number` - Order number (extracted from name)
- `date` - Formatted order date (Y-m-d H:i)
- `total` - Formatted total with currency (e.g., "100.00 USD")
- `currency` - Currency code
- `url` - Order admin URL
- `campaign` - UTM campaign
- `source` - UTM source
- `medium` - UTM medium
- `referring_site` - Referring site domain
- `category` - Categorized referrer (Social Media, Direct Links, Email, Other)

### Current UI
- Results are displayed in a table in `views/conversion.php`
- Table shows: Order #, Date, Total, Campaign, Source, Medium, Referring Site
- Results only appear when `$startDate && $endDate && empty($error)` and `!empty($orderData)`

## Implementation Plan

### Phase 1: Create CSV Export Endpoint

**File: `export.php`** (new file in root directory)

**Responsibilities:**
- Handle CSV export requests
- Validate shop parameter and authentication
- Retrieve order data using same logic as `conversion.php`
- Generate CSV file with proper headers
- Set appropriate HTTP headers for file download

**Key Features:**
- Accept `shop`, `start_date`, `end_date`, and `range` parameters (same as conversion.php)
- Reuse order fetching logic from `conversion.php` or `ConversionHelper`
- Generate CSV with all available fields
- Proper CSV escaping (handle commas, quotes, newlines)
- Set filename with date range: `conversion-data-YYYY-MM-DD-to-YYYY-MM-DD.csv`

**CSV Columns:**
1. Order Number
2. Order Date
3. Total Amount
4. Currency
5. Campaign
6. Source
7. Medium
8. Referring Site
9. Category
10. Order URL

### Phase 2: Add Export Button to UI

**File: `views/conversion.php`**

**Changes:**
- Add export button in the "Order Details" card section
- Button should appear after the table heading but before the table
- Button styling should match existing UI (Shopify Polaris-inspired design)
- Button should be:
  - **Hidden** when no results (`empty($orderData)`)
  - **Disabled** when no date range selected
  - **Enabled** when results are available

**Button Placement:**
- Add between line 286 (`<h2>Order Details</h2>`) and line 287 (`<?php if (empty($orderData)): ?>`)
- Or add in a button group above the table

**Button Behavior:**
- Link/button that opens export endpoint with current query parameters
- Should preserve current date range selection
- Opens in new tab/window or triggers download directly

### Phase 3: CSV Generation Logic

**CSV Formatting Requirements:**
- UTF-8 encoding with BOM for Excel compatibility
- Proper escaping of fields containing commas, quotes, or newlines
- Header row with column names
- One row per order

**CSV Content:**
- Include all fields from `$orderData` except `id` (internal use only)
- Format dates consistently
- Include raw numeric total (separate from formatted total) for better Excel compatibility
- Include order URL for reference

### Phase 4: Error Handling

**Scenarios to Handle:**
- No date range selected → Show error or redirect back
- No orders found → Return empty CSV or show message
- Invalid shop parameter → Return 400 error
- Missing access token → Return 500 error with helpful message
- Date range too large → Consider pagination or limit warning

## Technical Implementation Details

### File Structure
```
/export.php                    (new - CSV export endpoint)
/views/conversion.php          (modify - add export button)
/helpers/ConversionHelper.php  (may need helper method for CSV generation)
```

### Export Endpoint Flow
1. Validate shop parameter
2. Check shop installation and access token
3. Process date range (same logic as conversion.php)
4. Fetch orders using `ConversionHelper::getOrdersWithConversionData()`
5. Prepare order data (same as conversion.php)
6. Generate CSV content
7. Set HTTP headers for download
8. Output CSV content

### CSV Generation Function
Create a helper method in `ConversionHelper` or standalone function:
- `generateCSV(array $orderData): string`
- Handles CSV escaping using `fputcsv()` or manual escaping
- Returns CSV string

### HTTP Headers for CSV Download
```php
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="conversion-data-' . $startDate . '-to-' . $endDate . '.csv"');
header('Content-Transfer-Encoding: binary');
header('Pragma: no-cache');
header('Expires: 0');
```

### Button HTML/JavaScript
```html
<?php if (!empty($orderData) && $startDate && $endDate): ?>
    <div style="margin-bottom: 16px;">
        <a href="/export.php?shop=<?= urlencode($shop) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?><?= $dateRange ? '&range=' . urlencode($dateRange) : '' ?>" 
           class="export-btn" 
           target="_blank">
            Export to CSV
        </a>
    </div>
<?php endif; ?>
```

## Security Considerations

1. **Shop Validation**: Use `sanitize_shop_domain()` function
2. **Access Token Verification**: Ensure shop is installed and has valid token
3. **Date Range Validation**: Validate date formats and ranges
4. **Rate Limiting**: Consider adding rate limiting for export requests
5. **File Size**: Consider memory limits for large exports (1000+ orders)

## Testing Checklist

- [ ] Export button appears when results are available
- [ ] Export button is hidden when no results
- [ ] Export button is disabled/hidden when no date range selected
- [ ] CSV download works with preset date ranges (24h, 7d, 14d, 30d)
- [ ] CSV download works with custom date ranges
- [ ] CSV contains all expected columns
- [ ] CSV properly escapes special characters (commas, quotes, newlines)
- [ ] CSV opens correctly in Excel/Google Sheets
- [ ] Filename includes correct date range
- [ ] Error handling works for invalid shop
- [ ] Error handling works for missing access token
- [ ] Error handling works for no orders found

## Future Enhancements (Optional)

1. **Export Options**: Allow users to select which columns to export
2. **Export Format**: Support JSON, Excel (XLSX) formats
3. **Scheduled Exports**: Email CSV exports on schedule
4. **Export History**: Track export history per shop
5. **Large Dataset Handling**: Pagination or streaming for very large exports
6. **Statistics Export**: Include summary statistics in CSV

## Implementation Order

1. **Step 1**: Create `export.php` endpoint with basic CSV generation
2. **Step 2**: Test CSV generation with sample data
3. **Step 3**: Add export button to `views/conversion.php`
4. **Step 4**: Test button visibility and functionality
5. **Step 5**: Add error handling and edge cases
6. **Step 6**: Test with various date ranges and data scenarios
7. **Step 7**: Polish UI styling and user experience

## Notes

- Reuse existing code from `conversion.php` to avoid duplication
- Consider creating a shared function for order data preparation
- Ensure CSV is Excel-compatible (UTF-8 BOM, proper escaping)
- Keep export functionality consistent with existing app patterns
- Follow existing code style and conventions

