# Shopify Embedded App Skeleton (PHP)

This project is a minimal, fully-working OAuth skeleton for a Shopify **embedded app** written in **plain PHP** and designed to be hosted on a VPS.

It does *one* main thing:
- Implements the full OAuth install flow for any `{shop}.myshopify.com`
- Stores the shop access token
- Loads an embedded page in the Shopify Admin that shows:
  - The current shop's domain
  - The store name (fetched from Shopify)
  - (Optionally) the store email

From here, you can easily extend the app with additional pages, features, and API calls.

---

## 1. Files & Responsibilities

### Root
- **`config.php`**
  - Loads configuration constants (API key, secret, scopes, redirect URI, DB connection).
  - If `config.local.php` exists, it is loaded instead, so secrets can be stored there.

- **`config.local.example.php`**
  - Template file to create `config.local.php`.
  - Edit the values and rename to `config.local.php` for local/production use.

- **`db.php`**
  - Provides `get_db(): PDO` which returns a shared PDO instance for MySQL.

- **`migrations.sql`**
  - SQL to create the `shops` table used to store shop access tokens.

- **`index.php`**
  - Entry point for the embedded app.
  - Expects `?shop={shop-domain}`.
  - Looks up the shop's access token in the database.
  - If not installed, redirects to `install.php` to start OAuth.
  - If installed, calls Shopify `/admin/api/2024-01/shop.json` to get store info.
  - Passes data to `views/embedded.php` to render the UI.

- **`install.php`**
  - Starts the OAuth install flow.
  - Validates and normalizes the `shop` parameter.
  - Creates a random `state` value, stores it in the session.
  - Redirects the merchant to:
    - `https://{shop}/admin/oauth/authorize?client_id=...&scope=...&redirect_uri=...&state=...`

### `/auth`

- **`auth/callback.php`**
  - The OAuth callback endpoint (`SHOPIFY_REDIRECT_URI` should point here).
  - Validates:
    - required query params
    - `state` (matches session)
    - HMAC (using the shared secret)
  - Exchanges the temporary `code` for a permanent `access_token` by calling:
    - `https://{shop}/admin/oauth/access_token`
  - Stores/updates the shop record in the `shops` table.
  - Redirects back into `index.php?shop={shop}`, which will then load the embedded UI.

### `/helpers`

- **`helpers/hmac.php`**
  - `verify_shopify_hmac(array $query, string $shared_secret): bool`
    - Implements Shopify's HMAC verification.
  - `sanitize_shop_domain(?string $shop): ?string`
    - Normalizes and validates `{shop}.myshopify.com`.

- **`helpers/ShopifyClient.php`**
  - `ShopifyClient::getAccessToken(string $shop, string $code): ?string`
    - Exchanges the OAuth `code` for an access token.
  - `ShopifyClient::apiRequest(string $shop, string $accessToken, string $path, string $method = 'GET', ?array $data = null): array`
    - Minimal REST helper using `curl`.
    - Used by `index.php` to call `/admin/api/2024-01/shop.json`.

### `/views`

- **`views/embedded.php`**
  - Simple HTML view rendered inside Shopify Admin.
  - Shows shop domain, store name, and optional store email.
  - Sets up Shopify App Bridge:
    - Loads `@shopify/app-bridge` and `@shopify/app-bridge-utils`.
    - Creates the app with `apiKey` and `shopOrigin`.
    - Sets a basic `TitleBar`.

---

## 2. OAuth Flow Overview (For Reference)

1. Merchant clicks your install URL or visits:

   - `https://yourdomain.com/install.php?shop={theirshop}.myshopify.com`

2. `install.php`:
   - Validates `shop`.
   - Generates `state` and stores in `$_SESSION`.
   - Redirects to Shopify OAuth authorize endpoint.

3. Merchant approves the app in Shopify.

4. Shopify redirects back to:

   - `SHOPIFY_REDIRECT_URI` (e.g. `https://yourdomain.com/auth/callback.php`)

   with query params: `shop`, `code`, `state`, `hmac`, etc.

5. `auth/callback.php`:
   - Verifies `state` and HMAC.
   - Exchanges `code` for `access_token` using `ShopifyClient::getAccessToken`.
   - Stores `shop_domain` and `access_token` in the `shops` table.
   - Redirects to `index.php?shop={shop}`.

6. `index.php`:
   - Fetches access token from DB.
   - Calls Shopify `/shop.json` to get store info.
   - Renders `views/embedded.php` as an embedded app.

---

## 3. What Cursor Can Extend or Modify

Here are some natural extension points for Cursor:

1. **Add routes / pages**
   - Use simple PHP routing (e.g. `page` query param or small router) to support multiple embedded pages.
   - Split logic into controllers and views if desired.

2. **Improve authentication**
   - Implement session tokens via Shopify App Bridge / JWT verification for each request.
   - Add stricter validation and logging.

3. **Enhance UI**
   - Replace the barebones HTML with a small SPA (React/Vue) while still using this PHP backend.
   - Add Polaris components by serving a front-end built with a bundler.

4. **More Shopify API usage**
   - Add helpers in `ShopifyClient` for common tasks:
     - List products
     - Show orders
     - Create metafields, etc.

5. **Error handling & logging**
   - Add central error handler, logs, and better UX when API calls fail.

---

## 4. Setup Steps (For Human/Dev Operator)

1. **Create database & table**
   - Create a MySQL database (e.g. `shopify_app`).
   - Run `migrations.sql` in that database.

2. **Configure the app**
   - Copy `config.local.example.php` → `config.local.php`.
   - Fill in:
     - `SHOPIFY_API_KEY`
     - `SHOPIFY_API_SECRET`
     - `SHOPIFY_SCOPES` (e.g. `read_products` or another minimal scope you want).
     - `SHOPIFY_REDIRECT_URI` (e.g. `https://yourdomain.com/auth/callback.php`)
     - `DB_DSN`, `DB_USER`, `DB_PASS`.

3. **Set up the app in Shopify Partners**
   - Create a **Custom app** with:
     - App URL: `https://yourdomain.com/index.php`
     - Redirect URL: `https://yourdomain.com/auth/callback.php`
   - Enable **Embedded app**.
   - Ensure the scopes in the app match `SHOPIFY_SCOPES`.

4. **Install on a store (private / unlisted)**
   - Go to a merchant store:
     - Use direct URL: `https://{shop}.myshopify.com/admin/oauth/authorize?client_id=...`
     - Or simply hit `https://yourdomain.com/install.php?shop={shop}.myshopify.com`.

After installation, the app appears in the store's Admin Apps list (but not in the public Shopify App Store) and loads `index.php` embedded inside Admin.

---

This skeleton is intentionally small and explicit so Cursor can safely refactor, extend, or wrap it as you build more functionality.
