# Compliance webhook verification

When submitting to the Shopify App Store, the automation checks that your app not only subscribes to the mandatory compliance topics (`customers/data_request`, `customers/redact`, `shop/redact`) but also rejects requests that fail HMAC verification. The handler at `webhooks/compliance.php` calculates `X-Shopify-Hmac-Sha256` over the raw body and returns `400 Bad Request` when the signature does not match, which satisfies the requirement described in [Shopify's privacy law compliance guide](https://shopify.dev/docs/apps/build/compliance/privacy-law-compliance).

Before final deployment, manually exercise both paths so the review team can reproduce the behaviour:

```sh
# 1. Invalid HMAC → expect 400
curl -X POST https://backend.shopconversionhistory.com/webhooks/compliance.php \
  -H "Content-Type: application/json" \
  -H "X-Shopify-Hmac-Sha256: invalid" \
  -d '{"shop_domain":"example.myshopify.com"}' \
  -i

# 2. Valid HMAC → expect 200 (replace SECRET with your SHOPIFY_API_SECRET or SHOPIFY_WEBHOOK_SECRET)
payload='{"shop_domain":"example.myshopify.com"}'
hmac=$(printf '%s' "$payload" | openssl dgst -sha256 -mac HMAC -macopt "key:YOUR_SECRET" -binary | base64)
curl -X POST https://backend.shopconversionhistory.com/webhooks/compliance.php \
  -H "Content-Type: application/json" \
  -H "X-Shopify-Hmac-Sha256: $hmac" \
  -d "$payload" \
  -i
```

Record the HTTP status codes (`400 Bad Request` when the HMAC is bad, `200 OK` when the HMAC matches) so you can cite them in the App Store submission narrative. This proves the webhook endpoint enforces the HMAC validation described at https://shopify.dev/docs/apps/build/compliance/privacy-law-compliance.

## Important Notes

1. **For Custom Apps**: The `shopify.app.toml` file declares the compliance webhooks, but webhooks are also registered programmatically during installation in `auth/callback.php`. Both approaches ensure compliance.

2. **HMAC Verification**: The webhook handler verifies HMAC using either `SHOPIFY_API_SECRET` (for programmatically created webhooks) or `SHOPIFY_WEBHOOK_SECRET` (if configured in Partner Dashboard). Ensure your `config.local.php` has the correct secret configured.

3. **Deployment**: For Shopify CLI apps, deploy the `shopify.app.toml` configuration using `shopify app deploy`. For custom apps like this one, ensure webhooks are registered during installation and the endpoint is publicly accessible.
