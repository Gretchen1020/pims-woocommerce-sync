# PIMS — Product Information Management System (MVP)

API-first PIM that owns product data and syncs with WooCommerce via REST API v3.

## Requirements

- PHP 8.0+ (with `pdo_mysql`, `curl`, `json`, `mbstring`)
- MySQL 8.0.16+
- A WooCommerce store with REST API keys (read/write)

## Setup

1. **Config**

   ```bash
   cp config/config.example.php config/config.php
   # Edit config/config.php — DB credentials and WooCommerce URL/keys
   ```

2. **Database**

   ```bash
   mysql -u root -p < database/schema.sql
   ```

3. **Create a user**

   ```bash
   php database/create_user.php you@example.com 'your-password'
   ```

4. **Run**

   ```bash
   cd public
   php -S localhost:8080
   ```

   Open http://localhost:8080 and sign in.

## Connecting to WooCommerce

0. No WordPress yet? Install [Local](https://localwp.com) (free), create a site, then in WP admin go to **Plugins → Add New**, install and activate **WooCommerce** (skip its wizard).
1. In WP admin: **WooCommerce → Settings → Advanced → REST API → Add key**.
2. Choose an admin user, set **Permissions: Read/Write**, generate, and copy the consumer key (`ck_...`) and secret (`cs_...`). They are shown only once.
3. Put the store URL and keys in `config/config.php` (no trailing slash on the URL).
4. In WP admin go to **Settings → Permalinks** and click **Save** once (anything but "Plain"), otherwise the API returns `rest_no_route`.
5. Verify the connection:

   ```bash
   php database/test_woo.php
   ```

   It checks read access, write access (creates and deletes a throwaway category) and prints a hint for common failures.

**Auth modes:** an `https://` store uses HTTP Basic Auth with the key and secret. A plain `http://` store (e.g. a local WordPress) rejects Basic Auth, so the client sends the key and secret as query-string parameters, as the WooCommerce docs require for HTTP. This is chosen automatically from the URL scheme. For a local site with a self-signed certificate, set `verify_ssl` to `false`.

| Symptom | Fix |
|---|---|
| `Credentials rejected` (401) | Re-copy the key/secret; check the URL scheme matches the store |
| `does not have write permission` (403) | Regenerate the key with Read/Write |
| `rest_no_route` (404) | Settings → Permalinks → Save |
| `Network error` | Wrong URL/port, or WordPress is not running |

## What was built

### Already present (from prior work)
- Schema (`database/schema.sql`)
- Auth (sessions, CSRF, password hashing)
- HTTP layer (Router, Request, Response, HttpException)
- Bootstrap / Config / Database

### Completed in this pass

**Build step 1 — Catalog CRUD**
- `CategoryRepository` + `CatalogService` category methods
- `ProductRepository` + product CRUD with optimistic locking (`version`)
- Hard-delete vs archive rules
- Controllers + routes

**Build step 2 — Attributes**
- `AttributeRepository` + `AttributeService`
- Types: text, number, boolean, select, unit
- Options for select attributes
- Category ↔ attribute assignment with required flags
- Required-attribute enforcement before `active`
- Typed `product_attributes` values

**Build step 3 — Frontend**
- Vanilla JS SPA (`public/app.html` + `app.js` + `app.css`)
- Login, product list/form, categories, attributes, sync logs
- Category-driven attribute fields on the product form
- Conflict resolution UI

**Build step 4 — Sync**
- `WooCommerceClient` (cURL, 10s timeout, one retry on network/5xx)
- `SyncService`: create, three-way field-level merge, stock ownership rules
- Sync status machine + `sync_logs`
- Refresh stock endpoint

**Build step 5 — Global select attributes**
- Creates WC global attributes + terms on first use
- Links by name on collision

**Build step 6 — Import & conflicts**
- Paginated import (skip no-SKU, match by WC id then SKU)
- Field-level conflict detection + per-field / keep-all resolution

## API surface

| Area | Endpoints |
|------|-----------|
| Auth | `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me` |
| Products | `GET/POST /api/products`, `GET/PUT/DELETE /api/products/{id}`, `POST .../restore` |
| Categories | `GET/POST /api/categories`, `GET/PUT/DELETE /api/categories/{id}`, `PUT .../attributes` |
| Attributes | `GET/POST /api/attributes`, `GET/PUT/DELETE /api/attributes/{id}`, option CRUD |
| Sync | `POST /api/products/{id}/sync`, `GET .../sync-status`, `POST .../sync/resolve`, `POST .../refresh-stock`, `POST /api/sync/import`, `GET /api/sync/logs` |

All state-changing requests require the `X-CSRF-Token` header (token from `/api/auth/me` or login response).

## Acceptance criteria coverage

1. Category + attributes + product values — yes
2. Required attributes block `active` — yes
3. Sync creates WC product, stores `woocommerce_id`, re-sync updates — yes
4. Matching SKU links, no duplicate — yes
5. Import as drafts, skip no-SKU — yes
6. Independent field changes merge cleanly — yes (three-way compare)
7. Same-field divergence → CONFLICT + resolve — yes
8. Stock owned by WC after first push — yes
9. WC failure → FAILED + log — yes
10. Version conflict on concurrent edit — yes (409)
11. Credentials never exposed — yes (Config + client only)

## Notes

- Stock is editable in PIMS only until the first successful push.
- Archived products are hidden from the default product list (`include_archived=1` to show).
- A `SYNCING` state older than 5 minutes is auto-flipped to `FAILED`.
