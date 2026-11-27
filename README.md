# FreeSub — Subdomain, Cloudflare DNS & Edge SSL Management SaaS Platform

**FreeSub** is a modular PHP 8.3 + MySQL 8+ SaaS platform that lets developers claim free subdomains under community/custom root domains (such as `kharcha.info`) and manage real-time **Cloudflare Anycast DNS** (`A`, `AAAA`, `CNAME`, `MX`, `TXT`) and **SSL/TLS** settings from an InfinityFree-inspired web control panel — paired with a local loopback-only **Admin Desktop Console** for managing Cloudflare zones, users, quotas, and global rules.

---

## 1. Architecture Overview

```text
FreeSub/
├── .env.example                  # Documented environment template (copy to .env)
├── setup-check.php               # CLI-only environment & DB diagnostics / schema installer
├── database/
│   └── schema.sql                # MySQL 8+ schema (settings, users, root_domains, subdomains, dns_records)
├── core/                         # Shared backend core (kept OUTSIDE public web root)
│   ├── config.php                # Custom .env loader & typed Config singleton
│   ├── Database.php              # Strict UTF-8MB4 PDO singleton (native prepared statements)
│   ├── Validator.php             # Subdomain label, reserved word & RFC DNS record validators
│   ├── ClerkAuth.php             # Clerk RS256 JWT verification via cached JWKS + MySQL user sync
│   ├── CloudflareService.php     # Cloudflare REST API v4 cURL client (create/update/delete/list/verify)
│   └── cache/                    # Local JWKS cache (/core/cache/jwks.json)
├── web/                          # Public User SaaS Web Application (Document Root)
│   ├── launch-web.bat            # Windows local launcher (php -S 127.0.0.1:8000 -t web)
│   ├── index.php                 # Public landing page + live Subdomain Availability Checker
│   ├── dashboard.php             # Authenticated user dashboard, quota bar & Claim Subdomain modal
│   ├── manage.php                # InfinityFree-style subdomain DNS & SSL management workspace
│   ├── assets/
│   │   ├── styles.css            # High-contrast dark developer SaaS design system (Bootstrap 5.3)
│   │   └── app.js                # Clerk JS SDK loader, fresh-token API client & XSS-safe helpers
│   └── api/                      # Authenticated & public JSON REST API endpoints
│       ├── _bootstrap.php        # JSON/CORS headers, global error handler, auth & quota helpers
│       ├── bootstrap-config.php  # Public app config, Clerk publishable key & active root domains
│       ├── check-availability.php# Real-time label validation & availability check
│       ├── subdomains.php        # User subdomain list, transactional CF provisioning & deletion
│       └── dns-records.php       # Scoped CRUD for A, AAAA, CNAME, MX & TXT records
└── admin-desktop/                # Local Loopback-Only Admin Desktop Application (127.0.0.1:8787)
    ├── launch-admin.bat          # Starts PHP on 127.0.0.1:8787 & opens Edge/Chrome --app window
    ├── _auth.php                 # Loopback IP guard, session auth, CSRF protection & layout shell
    ├── login.php                 # Local passcode unlock screen (ADMIN_LOCAL_PASSCODE)
    ├── index.php                 # Platform KPI overview & recent subdomains
    ├── domains.php               # Root domains CRUD + live Cloudflare API credential tester
    ├── subdomains.php            # Global subdomain search, DNS inspector, suspend/unsuspend & force delete
    ├── users.php                 # Clerk-synced user directory, plan & quota overview
    ├── user-details.php          # Per-user custom subdomain limit, plan/status editor & owned subdomains
    └── settings.php              # Global free limits, max DNS per subdomain & custom reserved names
```

### Core Data Flow
1. **Clerk Auth (`ClerkAuth.php` + `web/assets/app.js`)**: The frontend initializes Clerk JS dynamically from `clerkPublishableKey` and calls `clerk.session.getToken()` before every API request so long sessions never fail due to stale JWTs. The PHP backend verifies the RS256 signature against Clerk's JWKS (cached in `/core/cache/jwks.json`) and upserts the user in MySQL `users`.
2. **Cloudflare DNS API v4 (`CloudflareService.php`)**: Each root domain in `root_domains` stores its own `cloudflare_zone_id` and scoped `cloudflare_api_token`. User DNS operations are strictly scoped to `{subdomain}.{root_domain}` (and child hosts like `www.{subdomain}.{root_domain}` or `_acme-challenge.{subdomain}.{root_domain}`) so a user can never modify the parent root zone or another user's subdomain.
3. **Isolated Local Admin Desktop (`/admin-desktop/`)**: Runs exclusively on `127.0.0.1:8787` via `launch-admin.bat`, protected by `ADMIN_LOCAL_PASSCODE`, `SameSite=Strict` sessions, and CSRF tokens — keeping administrative controls completely off the public internet.

---

## InfinityFree Shared Hosting Deployment

> **Not yet complete:** the remote Admin Bridge and `ADMIN_REMOTE_API_URL` support
> have not been implemented. The local Admin Desktop still requires direct MySQL
> access; do not expose it or use a public proxy as a workaround. Test this setup
> on a private staging site before relying on it in production.

1. Use HTTPS and confirm that the host supports PHP 8.2+, the extensions listed
   below, Apache `mod_rewrite`, `mod_setenvif`, `Require all denied`, and
   `.htaccess` overrides. Prefer a host that lets you put `core` and `.env`
   **outside** the public document root. The following layout depends entirely
   on the server honoring the supplied access rules; if any check fails, remove
   the sensitive files immediately and do not deploy this layout.
2. Upload the **contents** of `web/` (including the hidden `.htaccess`) into
   `htdocs/`, then upload `core/` and a production `.env` to `htdocs/`:

   ```text
   htdocs/
   ├── .htaccess          # web/.htaccess
   ├── .env               # private configuration, never commit
   ├── _paths.php         # web/_paths.php
   ├── index.php          # and other web/ files
   ├── api/               # web/api/
   ├── assets/            # web/assets/
   └── core/              # core/, including writable cache/
   ```

   Do not upload `admin-desktop/`, `.git/`, `setup-check.php` or `database/`.
   Import `database/schema.sql` using the hosting provider's phpMyAdmin instead;
   avoid rerunning the schema on an existing installation because its seed SQL
   resets settings. Configure the host-provided DB credentials in `.env`, set
   `APP_ENV=production`, `APP_DEBUG=false`, and `APP_URL` to your HTTPS origin.
   Use a strong, unique local admin passcode. `core/cache` must be writable by
   PHP, but should never be readable over HTTP.
3. Before entering live credentials or importing real data, verify access rules
   with harmless test files and an HTTPS client. From another machine run:

   ```sh
   curl -sS -o /dev/null -w '%{http_code}\n' https://your-domain.example/.env
   curl -sS -o /dev/null -w '%{http_code}\n' https://your-domain.example/.git/config
   curl -sS -o /dev/null -w '%{http_code}\n' https://your-domain.example/core/config.php
   curl -sS -o /dev/null -w '%{http_code}\n' https://your-domain.example/database/schema.sql
   curl -sS -o /dev/null -w '%{http_code}\n' https://your-domain.example/setup-check.php
   ```

   The expected status is 403; a 404 for files deliberately not uploaded is
   also acceptable. Test an actual harmless `.sql` file and a directory under
   `htdocs`, too; neither should be downloadable or listed. A 200, redirect to
   public content, or 500 does **not** establish protection. PHP's built-in
   server does not process `.htaccess`, so it cannot verify these rules.
4. In Clerk, configure the production instance and its allowed application
   origin(s) to match the HTTPS site. Set `CLERK_PUBLISHABLE_KEY`,
   `CLERK_ISSUER`, `CLERK_JWKS_URL` and `CLERK_AUTHORIZED_PARTIES` in the
   hosted `.env`. If using a Clerk development instance, follow Clerk's current
   restrictions on development domains. Verify an actual sign-in and API call
   on the host: preserving Authorization depends on its Apache/CGI setup.

## 2. Step-by-Step Local Setup on Windows (PHP 8.3 & MySQL 8+)

### Prerequisites
- **PHP 8.3+** (CLI in `PATH`) with extensions enabled in `php.ini`:
  - `pdo_mysql`, `curl`, `openssl`, `mbstring` (`json` is built in on PHP 8+)
- **MySQL 8.0+** running locally (e.g., MySQL Community Server, Laragon, XAMPP, or Herd)

### Step 1: Configure `.env`
Copy `.env.example` to `.env` in the repository root:

```cmd
copy .env.example .env
```

Edit `.env` and set your MySQL credentials, Clerk keys, and a strong local admin passcode:

```ini
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=freesub
DB_USER=root
DB_PASS=your_mysql_password

ADMIN_LOCAL_PASSCODE=your-strong-local-passcode
```

### Step 2: Run the Setup Diagnostics & Initialize the Database
From the repository root, run the CLI setup checker with `--init-db` to verify PHP extensions, create the `freesub` database if missing, and execute `/database/schema.sql` automatically:

```cmd
php setup-check.php --init-db
```

You can re-run `php setup-check.php` anytime to inspect your environment health and table row counts.

### Step 3: Launch the Local Apps
- **Start the Admin Desktop App (`http://127.0.0.1:8787`)**:
  Double-click `/admin-desktop/launch-admin.bat` (or run from Command Prompt):
  ```cmd
  admin-desktop\launch-admin.bat
  ```
  Sign in with your `ADMIN_LOCAL_PASSCODE`.

- **Start the User SaaS Web App (`http://127.0.0.1:8000`)**:
  Double-click `/web/launch-web.bat` (or run `php -S 127.0.0.1:8000 -t web`):
  ```cmd
  web\launch-web.bat
  ```

---

## 3. Configuring Cloudflare (`kharcha.info` Example)

To allow users to claim subdomains on a domain such as `kharcha.info`:

1. **Add the domain to Cloudflare** and ensure Cloudflare's nameservers are active for `kharcha.info`.
2. **Copy the Zone ID**:
   - Open the **Cloudflare Dashboard** &rarr; select `kharcha.info` &rarr; **Overview** tab.
   - Scroll down the right-hand **API** sidebar and copy the **Zone ID** (32-character hex string).
3. **Create a Scoped API Token**:
   - Go to **Cloudflare Dashboard** &rarr; **My Profile** &rarr; **API Tokens** &rarr; **Create Token**.
   - Choose **Create Custom Token** (or start from the *Edit zone DNS* template):
     - **Permissions**:
       - `Zone` &rarr; `DNS` &rarr; `Edit`
       - `Zone` &rarr; `Zone` &rarr; `Read`
     - **Zone Resources**:
       - `Include` &rarr; `Specific zone` &rarr; `kharcha.info`
   - Click **Continue to summary** &rarr; **Create Token**, and copy the generated token.
4. **Register the Zone in FreeSub Admin Desktop**:
   - Open FreeSub Admin Desktop (`http://127.0.0.1:8787`) &rarr; **Root Domains** (`domains.php`).
   - Enter:
     - **Root Domain Name**: `kharcha.info`
     - **Cloudflare Zone ID**: *(paste your 32-char Zone ID)*
     - **Cloudflare Scoped API Token**: *(paste your scoped token)*
     - **Active**: Checked
     - **Test Cloudflare API credentials before saving**: Checked
   - Click **Save Root Domain**. FreeSub verifies connectivity against Cloudflare API v4 and makes `.kharcha.info` immediately available on the public landing page and user dashboard.

---

## 4. Configuring Clerk Authentication

1. Create an application in the [Clerk Dashboard](https://dashboard.clerk.com/).
2. Open **Configure** &rarr; **API Keys** in Clerk:
   - Copy your **Publishable Key** (`pk_test_...` or `pk_live_...`).
   - Copy your **Secret Key** (`sk_test_...` or `sk_live_...`) *(optional, used as a fallback to hydrate email/full name if omitted from session claims)*.
3. Open **Configure** &rarr; **Domains / JWT Templates** (or API Keys &rarr; Frontend API URL):
   - Copy your Clerk **Frontend API / Issuer URL**, e.g. `https://actual-bobcat-12.clerk.accounts.dev`.
   - Your JWKS URL is `{CLERK_ISSUER}/.well-known/jwks.json`.
4. Update `.env`:
   ```ini
   CLERK_ISSUER=https://actual-bobcat-12.clerk.accounts.dev
   CLERK_JWKS_URL=https://actual-bobcat-12.clerk.accounts.dev/.well-known/jwks.json
   CLERK_PUBLISHABLE_KEY=pk_test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
   CLERK_SECRET_KEY=sk_test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
   CLERK_AUTHORIZED_PARTIES=http://127.0.0.1:8000,http://localhost:8000
   ```
5. Ensure `/core/cache/` is writable so `ClerkAuth` can cache `jwks.json` locally and avoid fetching JWKS on every API request.

---

## 5. Production Deployment Notes (Apache & Nginx)

For security, **point your web server's public Document Root strictly to `/web`**. This guarantees `/core/`, `/admin-desktop/`, `/database/`, `setup-check.php`, and `.env` are completely outside the public web root and can never be requested over HTTP.

### Nginx Example (`/etc/nginx/sites-available/freesub.conf`)
```nginx
server {
    listen 80;
    listen [::]:80;
    server_name freesub.yourdomain.com;

    # Point document root ONLY to /web
    root /var/www/freesub/web;
    index index.php;

    # Block dotfiles
    location ~ /\. {
        deny all;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
}
```

### Apache VirtualHost Example
```apache
<VirtualHost *:80>
    ServerName freesub.yourdomain.com
    DocumentRoot "/var/www/freesub/web"

    <Directory "/var/www/freesub/web">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Production Security Checklist
- Set `APP_ENV=production` and `APP_DEBUG=false` in `.env`.
- Restrict `CORS_ALLOWED_ORIGINS` and `CLERK_AUTHORIZED_PARTIES` in `.env` to your production HTTPS origin (e.g., `https://freesub.yourdomain.com`).
- Ensure `/core/cache` is writable by the PHP-FPM / web server user (`chown -R www-data:www-data /var/www/freesub/core/cache && chmod 750 /var/www/freesub/core/cache`).
- Keep `/admin-desktop` unexposed publicly; run it locally against a secure SSH tunnel to your production MySQL database (`ssh -L 3307:127.0.0.1:3306 user@prod-server`) when performing administrative tasks.
