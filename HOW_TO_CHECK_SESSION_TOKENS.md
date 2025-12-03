# How to Check if Session Tokens Are Working

## Step 1: Access Your App Through Shopify Admin

1. Go to your Shopify Admin
2. Navigate to **Apps** → **Conversion Compass App**
3. The URL will show `https://admin.shopify.com/store/...` - this is normal

## Step 2: Open Developer Tools

1. Press **F12** (or right-click → Inspect)
2. Go to the **Network** tab
3. **Clear** the network log (trash icon)

## Step 3: Reload the App Page

1. Refresh the page (F5 or Cmd+R)
2. Watch the Network tab fill with requests

## Step 4: Find Requests to Your Server

Look for requests to `backend.shopconversionhistory.com` - these are your app's requests.

Examples:
- `conversion.php`
- `index.php`
- `about.php`
- `debug-session-token.php`

## Step 5: Check the Request URL

1. Click on one of your app's requests (e.g., `conversion.php`)
2. Look at the **Request URL** in the details panel
3. **Check if it includes `&host=...`** (a long base64-encoded string)

### ✅ Good URL (has host parameter):
```
https://backend.shopconversionhistory.com/conversion.php?shop=frdmakesapps.myshopify.com&host=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

### ❌ Bad URL (missing host parameter):
```
https://backend.shopconversionhistory.com/conversion.php?shop=frdmakesapps.myshopify.com
```

## Step 6: Check Request Headers

1. Still in the Network tab, click on a request to your server
2. Go to the **Headers** section
3. Scroll down to **Request Headers**
4. Look for `X-Shopify-Session-Token` header

### ✅ Success:
- You see `X-Shopify-Session-Token: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...` (a long token)

### ❌ Failure:
- The header is missing

## Step 7: Check Browser Console

1. Go to the **Console** tab in Developer Tools
2. Look for messages starting with `=== APP BRIDGE DEBUG INFO ===`
3. Check for:
   - `hasHost: true` (good) or `hasHost: false` (bad)
   - `✓✓✓ Session tokens are working correctly!` (success)
   - `❌ CRITICAL: Host parameter is missing` (failure)

## Step 8: Test the Debug Endpoint

1. While viewing your app in Shopify Admin, open a new tab
2. Go to: `https://backend.shopconversionhistory.com/debug-session-token-viewer.html?shop=frdmakesapps.myshopify.com`
3. The page will show detailed debug information
4. Look for:
   - **Overall Status: PASS** (good) or **FAIL** (bad)
   - **Host parameter: OK** (good) or **FAIL** (bad)
   - **Session token present: OK** (good) or **FAIL** (bad)

## Common Issues

### Issue: Host parameter is missing
**Solution:** The app must be accessed through Shopify Admin, not directly. Make sure you're clicking the app from the Apps menu in Shopify Admin.

### Issue: Session token header is missing
**Solution:** 
1. Verify App Bridge is initialized with the `host` parameter
2. Check browser console for errors
3. Ensure you're using the latest App Bridge CDN script

### Issue: Console shows no debug messages
**Solution:** 
1. Make sure you've deployed the latest code changes
2. Hard refresh the page (Ctrl+Shift+R or Cmd+Shift+R)
3. Check if JavaScript errors are blocking execution

## Quick Checklist

- [ ] App is accessed through Shopify Admin (not directly)
- [ ] Network tab shows requests to `backend.shopconversionhistory.com`
- [ ] Request URLs include `&host=...` parameter
- [ ] Request headers include `X-Shopify-Session-Token`
- [ ] Browser console shows `hasHost: true`
- [ ] Browser console shows `✓ Session tokens are working correctly!`
- [ ] Debug endpoint shows `overall_status: PASS`

## Still Having Issues?

1. Check server error logs for detailed messages
2. Verify `composer install` has been run (JWT library)
3. Ensure all code changes have been deployed to production
4. Try clearing browser cache and hard refresh

