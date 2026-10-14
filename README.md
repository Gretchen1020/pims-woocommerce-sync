# PIMS: Product Information Management System

An API-first **Product Information Management (PIM)** system built with PHP and MySQL. It is the place where product data is created, structured and validated, and it keeps that data in sync with a **WooCommerce** store through the WooCommerce REST API v3.

Built as a hackathon project: backend, database, web UI and a live e-commerce integration.

**Demo video:** [Watch the demo ](https://drive.google.com/file/d/16ceukqp9inGdOOFVkdnkKWi6nvcdK7oI/view?usp=sharing)

**Technical specification:** [docs/PIMS Technical Specification.pdf](docs/PIMS%20Technical%20Specification.pdf)

---

## Table of contents

1. [What is a PIM?](#what-is-a-pim)
2. [Features](#features)
3. [Architecture](#architecture)
4. [Tech stack](#tech-stack)
5. [Project structure](#project-structure)
6. [Getting started](#getting-started)
7. [WooCommerce integration](#woocommerce-integration)
8. [How sync works](#how-sync-works)
9. [Demo walkthrough](#demo-walkthrough)
10. [API reference](#api-reference)
11. [Data model](#data-model)
12. [Design decisions](#design-decisions)
13. [Troubleshooting](#troubleshooting)
14. [Future scope](#future-scope)

---

## What is a PIM?

A PIM owns product information: SKUs, names, descriptions, prices, stock, categories and typed attributes. Teams edit products in one place, and the PIM publishes them to sales channels such as an online store. This avoids editing the same product by hand in several systems.

```
Categories → Attributes → Products → Sync with WooCommerce
```

## Features

- **Catalog management:** create, edit, archive and restore products and categories, with search and filters (name/SKU, category, brand, status, sync status).
- **Typed attributes:** text, number, boolean, select (with options) and unit attributes. Attributes are assigned per category, and each can be marked **required**.
- **Validation rules:** a product cannot become `active` until the category's required attributes are filled in.
- **Safe editing:** optimistic locking with a `version` field, so two people editing the same product get a `409` instead of silently overwriting each other.
- **WooCommerce push:** the first sync creates the product in WooCommerce; later syncs update the same product. An existing product with the same SKU is linked instead of duplicated.
- **Two-way merge:** sync compares PIMS, WooCommerce and the last-synced snapshot field by field. Independent changes merge cleanly; changes to the same field on both sides raise a **conflict**.
- **Conflict resolution UI:** choose the PIMS or WooCommerce value per field, or keep all PIMS values, then re-sync.
- **Import from WooCommerce:** paginated import that matches by WooCommerce ID, then SKU, skips products without a SKU, and brings new products in as drafts.
- **Stock ownership:** stock is editable in PIMS until the first successful push; after that WooCommerce owns it and PIMS can refresh it on demand.
- **Sync log:** every sync attempt is recorded with direction, outcome, error and HTTP status.
- **Security:** login with sessions, CSRF tokens on every state-changing request, hashed passwords. WooCommerce credentials stay in server-side config and are never sent to the browser.

### Sync status lifecycle

| Status | Meaning |
|---|---|
| `NOT_SYNCED` | Never pushed to WooCommerce |
| `SYNCING` | A sync is in progress (auto-marked `FAILED` after 5 minutes) |
| `SYNCED` | In step with WooCommerce |
| `CONFLICT` | The same field changed on both sides; needs resolving |
| `FAILED` | The last attempt failed; the error is stored and logged |

## Architecture

```
   Browser: vanilla JS single-page app (public/app.html, app.js, app.css)
                        │  JSON over /api  (session cookie + CSRF header)
                        ▼
   public/index.php  →  Router  →  Controllers
                                      │
                         ┌────────────┴─────────────┐
                         ▼                          ▼
                   Services (catalog,         Repositories (PDO)
                   attributes, sync)                │
                         │                          ▼
                         ▼                     MySQL 8
               WooCommerceClient (cURL)
                         │  WooCommerce REST API v3
                         ▼
                  WooCommerce store
```

Controllers stay thin, business rules live in services, SQL lives in repositories, and every WooCommerce call goes through one client class. Swapping the store or the database layer does not touch the rest.

## Tech stack

| Layer | Choice | Reason |
|---|---|---|
| Backend | PHP 8, no framework | Small custom router, controllers and PSR-4-style autoloader keep the code easy to follow |
| Database | MySQL 8 via PDO | Relational integrity: foreign keys, unique SKU, CHECK constraints |
| Frontend | Vanilla JavaScript SPA | No build step; open the page and it works |
| Integration | WooCommerce REST API v3 over cURL | The standard programmatic interface to WooCommerce |
| Auth to WooCommerce | Consumer key and secret | Basic Auth over HTTPS, query-string keys over plain HTTP |

## Project structure

```
pims-woocommerce-sync/
├── README.md
├── config/
│   └── config.example.php       # copy to config.php (git-ignored)
├── docs/
│   └── PIMS Technical Specification.pdf   # full technical specification
├── database/
│   ├── schema.sql               # tables and constraints
│   ├── create_user.php          # create a login from the CLI
│   └── test_woo.php             # check the WooCommerce connection
├── public/                      # web root
│   ├── index.php                # entry point and routes
│   ├── app.html, app.js, app.css
└── src/
    ├── Auth.php, Config.php, Database.php, bootstrap.php
    ├── Http/                    # Router, Request, Response, HttpException
    ├── Controllers/             # Auth, Category, Attribute, Product, Sync
    ├── Services/                # CatalogService, AttributeService, SyncService
    ├── Repositories/            # Product, Category, Attribute, SyncLog, User
    └── WooCommerce/             # WooCommerceClient, WooCommerceException
```

## Getting started

### Prerequisites

- **PHP 8.0+** with `pdo_mysql`, `curl`, `json` and `mbstring`
- **MySQL 8.0.16+** (CHECK constraints are enforced from this version)
- A **WooCommerce** store with REST API keys (a free local WordPress such as [Local](https://localwp.com) works well)

### 1. Configure

```bash
cp config/config.example.php config/config.php     # Windows: copy config\config.example.php config\config.php
```

Edit `config/config.php`: database credentials, WooCommerce store URL (no trailing slash), consumer key and secret. See [WooCommerce integration](#woocommerce-integration) for getting the keys.

### 2. Create the database

```bash
mysql -u root -p < database/schema.sql
```

On Windows PowerShell, `<` is not supported, so use:

```powershell
cmd /c "mysql.exe -u root < database\schema.sql"
```

### 3. Create a login

```bash
php database/create_user.php you@example.com "a-strong-password"
```

### 4. Check the WooCommerce connection

```bash
php database/test_woo.php
```

It checks read access and write access (creating and deleting a throwaway category) and prints a hint for common failures.

### 5. Run

```bash
cd public
php -S localhost:8080
```

Open **http://localhost:8080** and sign in.

## WooCommerce integration

### Getting API credentials

1. In WP admin, go to **WooCommerce → Settings → Advanced → REST API keys → Add key**.
2. Choose an admin user and set **Permissions** to **Read/Write**. A read-only key fails on sync.
3. Click **Generate API key** and copy the consumer key (`ck_...`) and consumer secret (`cs_...`). They are shown only once.
4. Go to **Settings → Permalinks**, choose anything except "Plain" (for example "Post name") and save. Otherwise the API returns `rest_no_route`.
5. Put the URL and keys in `config/config.php`.

### Authentication modes

The client picks the mode from the store URL:

| Store URL | Method |
|---|---|
| `https://...` | HTTP Basic Auth with the key and secret |
| `http://...` | Key and secret as query-string parameters (WooCommerce rejects Basic Auth over plain HTTP) |

For a local site with a self-signed certificate, set `verify_ssl` to `false` in `config/config.php` (a boolean, not a quoted string).

### Field mapping

| PIMS | WooCommerce |
|---|---|
| `name`, `description`, `sku` | `name`, `description`, `sku` |
| `price` | `regular_price` |
| `stock` | `manage_stock`, `stock_quantity` (first push only) |
| `status` (draft/active/inactive/archived) | `status` |
| category | `categories` (looked up by name, created if missing, ID stored) |
| attributes | global attributes with terms (select) or custom attributes (others) |

### Reliability

- 10 second timeout, and one automatic retry on network errors and 5xx responses.
- Failures set the product to `FAILED`, store the error and add a sync-log entry.
- A `SYNCING` state older than 5 minutes is treated as stuck and moved to `FAILED`.

## How sync works

1. If the product has a stored WooCommerce ID, fetch it. If it was deleted in WooCommerce, treat the product as new.
2. If there is no ID, look up WooCommerce by SKU and link a match instead of creating a duplicate.
3. For a linked product, compare each field (name, description, price, status, category, attributes) between PIMS, WooCommerce and the snapshot saved at the last sync:
   - changed only in PIMS → pushed
   - changed only in WooCommerce → pulled into PIMS
   - changed to the same value on both sides → no action
   - changed to different values on both sides → **conflict**, nothing is written
4. On success, a fresh snapshot is stored and the attempt is logged.

## Demo walkthrough

1. **Sign in** and open **Categories**; show a category with its assigned attributes, one marked required.
2. **Create a product** in that category. It starts as `NOT_SYNCED`.
3. **Sync** it: the status becomes `SYNCED`, and the product appears in WooCommerce admin under Products with the same SKU, price, stock and category.
4. **Change the price** in PIMS and sync again: the same product updates, with no duplicate.
5. **Conflict:** edit the product name in WooCommerce and in PIMS, then sync. The product becomes `CONFLICT`; resolve it in the UI and re-sync.
6. Open the **Sync logs** page to see every attempt.

## API reference

All state-changing requests need the `X-CSRF-Token` header (returned by `/api/auth/me` and login).

| Area | Endpoints |
|---|---|
| Auth | `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me` |
| Products | `GET/POST /api/products`, `GET/PUT/DELETE /api/products/{id}`, `POST /api/products/{id}/restore` |
| Categories | `GET/POST /api/categories`, `GET/PUT/DELETE /api/categories/{id}`, `PUT /api/categories/{id}/attributes` |
| Attributes | `GET/POST /api/attributes`, `GET/PUT/DELETE /api/attributes/{id}`, option routes under `/api/attributes/{id}/options` |
| Sync | `POST /api/products/{id}/sync`, `GET /api/products/{id}/sync-status`, `POST /api/products/{id}/sync/resolve`, `POST /api/products/{id}/refresh-stock`, `POST /api/sync/import`, `GET /api/sync/logs` |

List filters on `GET /api/products`: `search`, `category_id`, `brand`, `status`, `sync_status`, `include_archived`, `page`, `limit`.

## Data model

```
categories(id, name UNIQUE, description, woocommerce_id)
attributes(id, name UNIQUE, data_type, unit, woocommerce_id)
attribute_options(id, attribute_id, value, woocommerce_term_id)
category_attributes(category_id, attribute_id, required)
products(id, sku UNIQUE, name, description, category_id, brand, price, stock,
         status, version, woocommerce_id UNIQUE, sync_status, last_synced_at,
         last_sync_direction, last_sync_error, wc_snapshot JSON, conflict_fields JSON)
product_attributes(product_id, attribute_id, value_text | value_number |
                   value_boolean | option_id)      -- exactly one populated
sync_logs(id, product_id, direction, outcome, error, http_status, created_at)
users(id, email UNIQUE, password_hash)
```

## Design decisions

- **Attributes are data, not columns.** A category decides which typed attributes it needs, so new product types need no schema change.
- **Three-way comparison.** Storing the last-synced snapshot lets the system tell who changed what, so non-overlapping edits merge instead of conflicting.
- **Never overwrite silently.** Same-field divergence stops the sync and asks a person.
- **Optimistic locking.** A version number on products rejects stale concurrent edits.
- **One integration boundary.** `WooCommerceClient` is the only code that talks to the store, and credentials never leave it.
- **No framework.** A small router and controller layer keeps the flow of a request easy to trace.

## Troubleshooting

| Problem | Fix |
|---|---|
| `SSL certificate problem: self-signed certificate` | Set `'verify_ssl' => false` (boolean, no quotes) in `config/config.php` |
| `Credentials rejected` (401) | Re-copy the key and secret; check that the URL scheme matches the store |
| `does not have write permission` (403) | Generate a new key with **Read/Write** permission |
| `rest_no_route` (404) | WP admin → Settings → Permalinks → Save (not "Plain") |
| `Network error` | Wrong URL or port, or the WordPress site is not running |
| `Table ... already exists` on schema import | The database is already set up; skip the import (or drop the `pims` database to start clean) |
| `'php' is not recognized` | Use the full path, for example `C:\xampp\php\php.exe` |
| Product stuck in `CONFLICT` | Open it, choose a value per field and re-sync |
| First sync of an existing SKU reports a conflict | Expected: there is no snapshot yet, so differing values are flagged |

## Future scope

- Dashboard with sync-status counts and a "sync all pending" action
- Product variants and images
- Webhooks from WooCommerce for near-real-time updates
- Roles and an audit trail
- Automated tests for the sync merge logic