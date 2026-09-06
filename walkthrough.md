# DZ Carrier Manager — Phase 2 & Webhook Integration Walkthrough

This document details the architecture, configuration, and troubleshooting steps completed for the **DZ Carrier Manager** PrestaShop module, with a specific focus on **Public Tunnels**, **Webhook Reception URLs**, **Security Verification**, and **PrestaShop Multi-Domain Handling**.

---

## 1. Overview & Architecture

The module enables automated carrier dispatching and real-time shipment status synchronization for Algerian e-commerce stores (starting with **Guepex / Yalidine**).

```
┌────────────────────────────────┐            ┌──────────────────────────────────────────────┐
│  Guepex Webhook Engine         │            │  Local PrestaShop Development Environment   │
│                                │            │                                              │
│  1. CRC Validation (GET)       │  HTTPS     │  Cloudflare Tunnel (cloudflared)             │
│  ────────────────────────────► │ ─────────► │  Forwarding to 127.0.0.1:80                  │
│                                │            │  (Host: prestashop.test)                     │
│  2. Event Notifications (POST) │            │                      │                       │
│     [X_YALIDINE_SIGNATURE]     │            │                      ▼                       │
│  ────────────────────────────► │ ─────────► │  /modules/dzcarriermanager/webhook.php       │
│                                │            │  - CRC Challenge Handler                     │
│  ◄──────────────────────────── │ ◄───────── │  - HMAC-SHA256 Signature Validator           │
│     HTTP 200 OK                │            │  - Event Deduplicator & Status Handler       │
│                                │            │                      │                       │
│                                │            │                      ▼                       │
│                                │            │  ps_cm_parcels & ps_cm_parcel_histories      │
└────────────────────────────────┘            └──────────────────────────────────────────────┘
```

---

## 2. Public Tunnel Setup: Cloudflare Tunnel vs. ngrok

During local development, external carrier servers (e.g. `guepex.app`) cannot send HTTP/HTTPS requests to local domains like `prestashop.test` or `localhost`. A public tunnel is required.

### Why ngrok Free Tier Failed
- On ngrok free tier (`*.ngrok-free.dev` / `*.ngrok-free.app`), ngrok injects an HTML browser warning page (*"You are about to visit..."*) on GET requests.
- When Guepex attempted to validate the webhook endpoint via GET CRC challenge, ngrok returned this HTML page instead of the plain-text token. Guepex failed with:
  > *"Votre webhook n'a pas été validé : Votre lien n'a pas retourné le code http 200"*

### The Solution: Cloudflare Tunnel (`cloudflared`)
- **No warning pages**: Requests are passed directly to the local server without HTML interstitials.
- **No rate-limiting or account required** for quick tunnels.
- **Fast response**: Returns plain text CRC responses in $< 50\text{ ms}$.

### How to Start the Cloudflare Tunnel
A portable `cloudflared.exe` is installed in `C:\laragon\bin\cloudflared.exe`.

Run the following command in PowerShell:
```powershell
C:\laragon\bin\cloudflared.exe tunnel --url http://127.0.0.1:80 --http-host-header prestashop.test
```

> [!IMPORTANT]
> The `--http-host-header prestashop.test` flag is **critical**. It tells Apache to route incoming requests directly to the `prestashop.test` virtual host document root instead of Laragon's default root.

When started, it outputs your active tunnel URL:
```text
+--------------------------------------------------------------------------------------------+
|  Your quick Tunnel has been created! Visit it at:                                          |
|  https://xxxx-xxxx-xxxx.trycloudflare.com                                                  |
+--------------------------------------------------------------------------------------------+
```

---

## 3. Webhook Reception URL Configuration

### Endpoint Paths
The module provides two interchangeable webhook entry points:
1. **Standalone Fast Endpoint (Recommended)**:
   `https://<YOUR-TUNNEL-DOMAIN>/modules/dzcarriermanager/webhook.php?carrier=guepex`
2. **PrestaShop ModuleFrontController**:
   `https://<YOUR-TUNNEL-DOMAIN>/index.php?fc=module&module=dzcarriermanager&controller=webhook&carrier=guepex`

### Order of Operations to Register with Guepex:
1. **Start the Tunnel**: Run `cloudflared` to obtain the active public URL.
2. **Register in Guepex Dashboard** (`https://guepex.app/app/dev/webhooks/index.php`):
   - **Name**: e.g., `PrestaShop Store`
   - **Reception URL**: `https://<YOUR-TUNNEL-DOMAIN>/modules/dzcarriermanager/webhook.php?carrier=guepex`
   - **Email**: Notification email.
   - **Events**: Select all (`parcel_status_updated`, `parcel_created`, `parcel_edited`, `parcel_deleted`, `parcel_payment_updated`).
   - Click **Proceed** $\rightarrow$ Guepex performs an automated CRC GET check and creates the webhook.
3. **Save Secret Key in PrestaShop**:
   - Copy the generated **Secret Key** from Guepex.
   - In PrestaShop Admin: **Shipping $\rightarrow$ DZ Carrier Manager $\rightarrow$ My Carriers $\rightarrow$ Edit (Guepex)**.
   - Paste into **Webhook Secret Key** and click **Save**.
4. **Activate Webhook**:
   - In Guepex Dashboard, toggle status to **Active** (green).
   - Click **Make Tests $\rightarrow$ Send a test** to verify real-time 200 OK delivery.

---

## 4. Key Fixes & Troubleshooting Applied

### A. Fixing Apache `.htaccess` 403 / Fallback Rewrite
- **Issue**: PrestaShop's `/modules/.htaccess` had a generic `<FilesMatch "(\.php..."> Require all denied` rule that blocked direct access to `webhook.php`. Apache fell back to `index.php`, causing routing failures.
- **Fix**: Added an explicit grant rule to [c:/laragon/www/prestashop/modules/.htaccess](file:///c:/laragon/www/prestashop/modules/.htaccess):
  ```apache
  <Files "webhook.php">
      <IfModule mod_authz_core.c>
          Require all granted
      </IfModule>
      <IfModule !mod_authz_core.c>
          Order allow,deny
          Allow from all
      </IfModule>
  </Files>
  ```

### B. Fixing PrestaShop 302 Canonical Domain Redirection on POST
- **Issue**: When POST webhook events arrived via the tunnel domain (e.g. `*.trycloudflare.com`), PrestaShop's bootstrap (`Shop::initialize()`) detected that the host differed from `prestashop.test` in `ps_shop_url` and issued a `302 Found` redirect to `https://prestashop.test/?carrier=guepex`.
- **Fix**:
  1. In [webhook.php](file:///c:/laragon/www/prestashop/modules/dzcarriermanager/webhook.php), defined `_PS_ADMIN_DIR_` before loading `config.inc.php`. This signals PrestaShop that the request is an internal backend process, completely disabling front-office shop domain redirection.
  2. In [controllers/front/webhook.php](file:///c:/laragon/www/prestashop/modules/dzcarriermanager/controllers/front/webhook.php), overrode `canonicalRedirection()` with an empty method.

### C. Fixing Symfony Admin Controller `getContext()` 500 Error
- **Issue**: `CarrierAccountController::editAction` threw an `UndefinedMethodError` when trying to call `$this->getContext()`.
- **Fix**: Replaced with `\Context::getContext()->shop->getBaseURL(true)`.

### D. Native Multi-Tab Navigation Hierarchy
- **Architecture**:
  - Registered **AdminDzCarrierManager** (ID 163) under `AdminParentShipping` (ID 46).
  - Sub-tab 1: **AdminDzCarrierManagerParcels** (ID 161) — route: `ps_dzcarriermanager_parcel_index`.
  - Sub-tab 2: **AdminDzCarrierManagerCarriers** (ID 162) — route: `ps_dzcarriermanager_carrier_index`.
- **Result**: Clean single entry in the sidebar (`DZ Carrier Manager`) with PrestaShop's native top tabs (`Parcels | My Carriers`) on all pages. Redundant custom tab bars were removed and Twig cache cleared.

---

## 5. Quick Verification Commands for Future Agents

### 1. Test CRC Challenge:
```powershell
curl.exe -i "https://<ACTIVE-TUNNEL-URL>/modules/dzcarriermanager/webhook.php?carrier=guepex&subscribe=1&crc_token=test_token_123"
# Expected response: HTTP/1.1 200 OK with body "test_token_123"
```

### 2. Test Local Endpoint POST:
```powershell
curl.exe -i -X POST "http://prestashop.test/modules/dzcarriermanager/webhook.php?carrier=guepex" -H "Content-Type: application/json" -d "{\"type\":\"parcel_status_updated\",\"events\":[]}"
# Expected response: HTTP 400 with {"error":"Invalid signature verification."} (confirms DB and signature checks are working)
```

### 3. Clear Twig / Smarty Cache:
```powershell
if (Test-Path "c:\laragon\www\prestashop\var\cache\prod\twig") { Remove-Item -Path "c:\laragon\www\prestashop\var\cache\prod\twig" -Recurse -Force }
php -r "require 'c:/laragon/www/prestashop/config/config.inc.php'; Tools::clearSmartyCache(); Tools::clearXMLCache(); echo 'Cache cleared.';"
```
