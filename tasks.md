# Shopify Skeleton App - Development Tasks

## Setup & Configuration

- [ ] Create `config.local.php` from `config.local.example.php`
- [ ] Configure Shopify API credentials in `config.local.php`:
  - [ ] Set `SHOPIFY_API_KEY` (from Shopify Partners dashboard)
  - [ ] Set `SHOPIFY_API_SECRET` (from Shopify Partners dashboard)
  - [ ] Set `SHOPIFY_SCOPES` (e.g., `read_products` or other required scopes)
  - [ ] Set `SHOPIFY_REDIRECT_URI` to your production callback URL
- [ ] Configure database credentials in `config.local.php`:
  - [ ] Set `DB_DSN` (database connection string)
  - [ ] Set `DB_USER` (database username)
  - [ ] Set `DB_PASS` (database password)
- [ ] Create MySQL database
- [ ] Run `migrations.sql` to create the `shops` table

## Shopify Partners Setup

- [ ] Create app in Shopify Partners dashboard
  - [ ] Set App URL (e.g., `https://yourdomain.com/index.php`)
  - [ ] Set Redirect URL (e.g., `https://yourdomain.com/auth/callback.php`)
  - [ ] Enable "Embedded app" option
  - [ ] Configure required scopes (must match `SHOPIFY_SCOPES` in config)
  - [ ] Save API Key and API Secret

## Testing

- [ ] Test OAuth install flow:
  - [ ] Visit `install.php?shop={test-store}.myshopify.com`
  - [ ] Complete OAuth authorization
  - [ ] Verify callback processes correctly
  - [ ] Verify token is stored in database
- [ ] Test embedded app:
  - [ ] Access app from Shopify Admin
  - [ ] Verify shop info displays correctly
  - [ ] Verify App Bridge integration works

## Deployment

- [ ] Ensure HTTPS is configured (required for Shopify apps)
- [ ] Verify `config.local.php` is not committed to git (should be in `.gitignore`)
- [ ] Test production OAuth flow
- [ ] Verify database connection works on production server

## Notes

- This app supports multiple unrelated stores - each store gets its own entry in the `shops` table
- The same API Key and Secret are used for all stores (they're your app credentials)
- Each store will have its own access token stored separately
- Installation is done per-store via `install.php?shop={store}.myshopify.com`

