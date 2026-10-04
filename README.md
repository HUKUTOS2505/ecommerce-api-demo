# E-commerce REST API Demo

A working PHP backend with a small browser playground. Browse fictional products and variants, manage a cart, create a demo order and inspect the actual HTTP requests and JSON responses.

- [Live demo](https://nikitazalesskiy.ru/en/lab/ecommerce-api/)
- [API documentation](https://nikitazalesskiy.ru/en/lab/ecommerce-api/docs/)
- [Portfolio](https://nikitazalesskiy.ru/en/)

This is original demonstration code. It contains no client source code, business-system connections, payment processing or production credentials. Commercial projects are described separately in the portfolio.

## Features

- Ten fictional products with three variants each, including sold-out offers.
- Server-side search, category/stock filters, sorting and pagination.
- Integer-cent prices, quantity validation and stock-conflict responses.
- Persistent per-browser carts, orders and inventory, isolated from other visitors.
- Transactional checkout and idempotency keys to prevent duplicate orders.
- Customer View and Developer View with real request/response inspection.
- Reset Demo and expiration after one hour of inactivity.
- English API documentation, read-only request examples and an OpenAPI 3.0 file.
- Same-origin checks, CSRF tokens, body limits and rate limits.

## Local setup

Requirements: PHP 8.2 or newer with PDO SQLite. No Composer, Node build or database server is required.

```sh
git clone https://github.com/HUKUTOS2505/ecommerce-api-demo.git
cd ecommerce-api-demo
php -m
php -S 127.0.0.1:8080 -t public router.php
```

Confirm that `PDO` and `pdo_sqlite` appear in `php -m`. Open:

```text
http://127.0.0.1:8080/en/lab/ecommerce-api/
```

The app creates `var/sandbox.sqlite` on first API use. The PHP process needs write access to `var/`. To place the database elsewhere, set `DEMO_DATA_DIR` before starting PHP. The built-in server is for local development only.

For the functional API checks, leave the server running and use another terminal with Python 3:

```sh
python3 tests/api_smoke.py http://127.0.0.1:8080
```

On Windows, `python` can be used instead of `python3`. The tests use only Python’s standard library and create/reset their own sandbox cookies.

## Architecture

`Catalog` defines fictional catalog data. `Api` validates HTTP input and implements the workflows. `SandboxStore` persists visitor-specific inventory, carts and orders in SQLite using prepared statements and `BEGIN IMMEDIATE` transactions. The bootstrap layer sets response headers and maps errors to HTTP statuses.

SQLite is a deliberate choice for a self-contained demo that runs without an external database. Each sandbox is stored as a bounded state snapshot. This keeps isolation and reset simple; it is not a proposed production architecture for a large store.

Prices use integer cents. Adding to a cart does not reserve stock. Checkout rechecks stock, snapshots the cart lines into an order, reduces that visitor’s inventory and empties the cart in one transaction.

The frontend uses plain JavaScript and Fetch. Its inspector records the requests the UI actually sends; it does not manufacture responses. Observed request timings include the network round trip and are not performance benchmarks.

## API endpoints

Base path: `/api/demo/v1`.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/products` | Search, filter, sort and paginate |
| GET | `/products/{id}` | Product and variant detail |
| GET | `/cart` | Current cart and CSRF response header |
| POST | `/cart/items` | Add a variant or merge its quantity |
| PATCH | `/cart/items/{id}` | Set quantity |
| DELETE | `/cart/items/{id}` | Remove a line |
| POST | `/orders` | Create a demo order |
| GET | `/orders/{id}` | Read an order from your own sandbox |
| POST | `/reset` | Reset your stock, cart and orders |

Product query parameters: `q`, `category`, `in_stock`, `sort`, `page`, `per_page`. Sort values: `name_asc`, `price_asc`, `price_desc`. Categories: `accessories`, `electronics`, `workspace`.

## Example requests and responses

```sh
curl 'http://127.0.0.1:8080/api/demo/v1/products?category=accessories&sort=price_asc&per_page=3'
curl -c cookies.txt -D headers.txt 'http://127.0.0.1:8080/api/demo/v1/cart'
```

Read the `X-CSRF-Token` value from `headers.txt`. Use the same cookie jar for mutations:

```sh
curl -b cookies.txt -c cookies.txt \
  -H 'Content-Type: application/json' \
  -H 'X-CSRF-Token: YOUR_SESSION_TOKEN' \
  -d '{"product_id":1,"variant_id":11,"quantity":1}' \
  'http://127.0.0.1:8080/api/demo/v1/cart/items'
```

A new cart line returns HTTP 201:

```json
{
  "data": {
    "cart": {
      "items": [
        {
          "id": 11,
          "product_id": 1,
          "variant_id": 11,
          "quantity": 1,
          "product_name": "Canvas Carryall",
          "variant_label": "Natural",
          "unit_price_cents": 3200,
          "subtotal_cents": 3200,
          "available_stock": 8
        }
      ],
      "quantity": 1,
      "total_cents": 3200,
      "currency": "USD"
    }
  }
}
```

Checkout uses the same cookie and CSRF header plus an `Idempotency-Key`:

```sh
curl -b cookies.txt \
  -H 'Content-Type: application/json' \
  -H 'X-CSRF-Token: YOUR_SESSION_TOKEN' \
  -H 'Idempotency-Key: checkout-example-01' \
  -d '{"delivery_method":"demo_pickup","demo_acknowledged":true}' \
  'http://127.0.0.1:8080/api/demo/v1/orders'
```

Repeat the same key and body to read the original order with HTTP 200 and `replayed: true`. A different body with that key returns 409. The only delivery options are `demo_pickup` and `demo_shipping`; no address, email, payment or real delivery is requested.

## Error responses

Errors share an `error` object with `code`, `message` and `details`. Examples include malformed JSON (400), origin/CSRF failures (403), missing resources (404), wrong methods (405), stock or empty-cart conflicts (409), oversized bodies (413), content-type failures (415), validation (422), rate limits (429) and temporary storage failures (503).

```json
{
  "error": {
    "code": "insufficient_stock",
    "message": "Not enough stock for this variant.",
    "details": { "available_stock": 0, "cart_quantity": 0 }
  }
}
```

## Project structure

```text
src/                     PHP API, catalog, persistence and response bootstrap
public/api/demo/v1/       Apache API entry point and routing
public/en/lab/ecommerce-api/
  assets/                JavaScript, CSS and original SVG illustrations
  docs/                  Documentation, read-only examples and OpenAPI JSON
router.php               Local PHP development router
tests/api_smoke.py        Functional HTTP checks
tools/build_docs.py       Documentation generator (Python standard library)
var/                     Local runtime data, ignored by Git
```

The repository intentionally contains only the demonstration, not the commercial portfolio or the Russian site.

## Security and deployment notes

- The cookie is random, HttpOnly, SameSite=Strict and Secure on the live host.
- CSRF tokens are session-bound. Cross-origin requests are rejected; no wildcard CORS is enabled.
- Mutation bodies are limited to 8 KB. Fields, types, quantities and supported values are validated.
- SQLite writes use prepared statements and transactions. There is no arbitrary SQL, executable input or file upload endpoint.
- Sandboxes expire after one hour of inactivity. Each holds at most 20 orders. There are at most 1,000 active sandboxes.
- Limits are 120 requests/minute per sandbox and 300 per server-observed IP; 429 responses include `Retry-After: 60`.
- Reset affects only the requesting browser; it does not bypass rate limits.
- Runtime databases, logs and environment files must not be committed or exposed under the web root.

For Apache, copy `public/` contents under the site’s web root and retain the API `.htaccess`. Prefer keeping `src/` and `var/` outside the web root, adjusting the entry point to your application path. The live shared host restricts PHP file access to the site directory, so it uses the existing HTTP-denied `private/ecommerce-demo/` area under the web root instead. That area must have an effective server-side deny rule; `.htaccess` alone is insufficient if your server bypasses it. Verify that source and database URLs return 403, and never deploy runtime data to a publicly accessible directory. Back up existing files and test routing before exposing the endpoint.

This is a public demo, not an authenticated store, a payment system or a production deployment template.

## What this project demonstrates

Server-side catalog workflows, offer-level pricing and stock, cart validation, transactional order creation, request/response design, error handling and an interface that makes backend behaviour observable.

The illustrations are original SVG drawings included in this repository. The code is available under the MIT license.
