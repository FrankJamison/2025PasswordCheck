# Password Exposure Checker (PHP + Python)

A small, privacy-minded web utility that checks whether a password appears in the public **Have I Been Pwned (HIBP) Pwned Passwords** dataset.

This project is intentionally lightweight (no database, no user accounts) and is designed to run locally on **XAMPP (Windows)** or on a typical **Linux web host**.

## Why this exists (recruiter / employer friendly)
- Demonstrates practical secure-integration patterns (k‑Anonymity via HIBP Range API).
- Shows clean separation of concerns: PHP web layer + Python service logic.
- Focuses on user privacy: the application does not store passwords and never sends a full password to HIBP.
- Production-minded details: configurable interpreter path, safe(ish) debug mode, clear failure reporting.

## What it does
- Accepts a password via a simple form.
- Computes SHA‑1 and uses the **HIBP “range” endpoint** (k‑Anonymity) to look up a partial hash.
- Displays how many times the password appeared in breach corpuses.

> Important: This checks *exposure frequency* in the dataset. It does not validate password strength beyond that.

## Tech stack
- **Frontend:** HTML + CSS (responsive, minimal UI)
- **Backend (web):** PHP (handles form submission, runs Python, returns JSON)
- **Backend (logic):** Python 3 + `requests` (calls HIBP Range API)
- **External service:** HIBP Pwned Passwords Range API

## Design elements (UI/UX)
- Minimal layout with clear primary action (password input + “Check”).
- Responsive container that works well on mobile and desktop.
- Results are shown in a `<pre>` region for readable output.
- Basic accessibility: `aria-live="polite"` result updates for screen readers.

## Architecture

Request flow:

1. Browser submits password (POST)
2. `index.php` runs `check_password.py` (via `exec()`)
3. Python script:
    - SHA‑1 hashes the password
    - Sends **only the first 5 hash characters** to HIBP
    - Compares returned suffixes locally
4. PHP returns the result as JSON to the browser

High-level diagram:

```
Browser
   | POST / (password)
   v
index.php (PHP)
   | exec python + args
   v
check_password.py (Python)
   | GET https://api.pwnedpasswords.com/range/{first5}
   v
HIBP Range API
```

## Security & privacy notes

### What is protected
- **Full passwords are not sent to HIBP.** Only a 5‑character SHA‑1 prefix is sent (k‑Anonymity model).
- The project is designed not to store user input.

### What you should still be aware of
- The password is submitted to *your server* (PHP) in plaintext over HTTP unless you run it locally or behind HTTPS.
   - For anything beyond local use, put it behind **TLS (HTTPS)**.
- Debug mode can include Python stderr in responses; keep `PW_CHECKER_DEBUG` disabled in production.

## Production HTTPS (important)

If the browser shows **HTTPS crossed out** / “Not secure”, it usually means:

- You are actually on `http://...` (no TLS)
- The TLS certificate is invalid (expired, wrong hostname, missing chain)
- A proxy/CDN is terminating TLS incorrectly

This app also enforces HTTPS at the application layer for non-local hosts (see [index.php](index.php)).

### Production (AlmaLinux + Webuzo Apache)

Webuzo commonly runs its own Apache at:

- `/usr/local/apps/apache2/bin/httpd`

In that environment, `certbot --apache` can fail because it tries to restart the system Apache (`/usr/sbin/httpd`) while Webuzo’s Apache already owns ports `80/443`.

Use **Certbot webroot mode** and keep Webuzo’s SSL PEM updated.

#### 1) Find the site DocumentRoot

```bash
sudo /usr/local/apps/apache2/bin/httpd -S
```

Locate the `VirtualHost` for your domain and its `DocumentRoot` (often defined in `/usr/local/apps/apache2/etc/conf.d/webuzoVH.conf`).

#### 2) Allow HTTP-01 challenge path

Create/update the per-domain include (Webuzo includes this automatically):

- `/var/webuzo-data/apache2/custom/domains/<your-domain>.conf`

Example for `passwordcheck.fcjamison.com`:

```apache
Alias "/.well-known/acme-challenge/" "/home/frankjamison/public_html/fcjamison.com/projects/2025PasswordCheck/.well-known/acme-challenge/"

<Directory "/home/frankjamison/public_html/fcjamison.com/projects/2025PasswordCheck/.well-known/acme-challenge/">
      AllowOverride None
      Options SymLinksIfOwnerMatch
      Require all granted
</Directory>

<LocationMatch "^/\.well-known/acme-challenge/">
      Require all granted
</LocationMatch>
```

Then reload Webuzo Apache:

```bash
sudo /usr/local/apps/apache2/bin/httpd -t && sudo /usr/local/apps/apache2/bin/httpd -k graceful
```

#### 3) Issue the certificate (webroot)

```bash
sudo certbot certonly --webroot \
   -w /path/to/your/DocumentRoot \
   -d yourdomain.example \
   -d www.yourdomain.example
```

#### 4) Make Webuzo’s SSL PEM use Let’s Encrypt

If the Webuzo vhost references a generated combined PEM like:

- `/var/webuzo/users/<user>/ssl/<domain>-combined.pem`

You can replace its contents with Let’s Encrypt’s `fullchain.pem` + `privkey.pem` and reload Apache.

#### 5) Keep it working on renew

Use a Certbot deploy hook (`/etc/letsencrypt/renewal-hooks/deploy/`) to rebuild the combined PEM and gracefully reload Webuzo Apache.

## Repository structure
```
.
├─ index.php              # Web UI + POST handler; invokes Python
├─ check_password.py      # HIBP Range API integration (k-Anonymity)
├─ css/
│  └─ styles.css          # Styling
├─ js/
│  └─ app.js              # Frontend behavior (externalized for CSP)
└─ README.md
```

## Setup (Windows + XAMPP)

### Prerequisites
- XAMPP (Apache + PHP)
- Python 3.x
- Python package: `requests`

Install Python dependency:
```bash
python -m pip install requests
```

### Option A: run under `localhost` (quickest)
1. Copy/clone this folder into your XAMPP web root:
    - `C:\xampp\htdocs\2025PasswordCheck`
2. Browse:
    - `http://localhost/2025PasswordCheck/`

### Option B: VirtualHost (`2025passwordcheck.localhost`)
1. Ensure VirtualHosts file is included:
    - In `C:\xampp\apache\conf\httpd.conf`, confirm:
       - `Include conf/extra/httpd-vhosts.conf`
2. Add a VirtualHost entry in `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:
    ```apache
    <VirtualHost *:80>
          ServerName 2025passwordcheck.localhost
          DocumentRoot "D:/Websites/2026FCJamison/projects/2025PasswordCheck"

          <Directory "D:/Websites/2026FCJamison/projects/2025PasswordCheck">
                Require all granted
                AllowOverride All
                Options Indexes FollowSymLinks
          </Directory>
    </VirtualHost>
    ```
3. Restart Apache from the XAMPP Control Panel.
4. Browse:
    - `http://2025passwordcheck.localhost/`

Notes:
- `.localhost` typically resolves to `127.0.0.1` automatically, so you usually do not need to edit your hosts file.
- If you see the XAMPP dashboard, Apache is not matching your vhost (restart Apache and verify the vhost file is included).

## Setup (Linux / typical hosting)

### Prerequisites
- Apache or Nginx + PHP 7+
- Python 3 + `requests`

Install dependency:
```bash
python3 -m pip install requests
```

Serve the directory with your web server (DocumentRoot pointing at this folder) and browse to the configured site.

## Configuration

Environment variables (optional but recommended):

- `PYTHON_BIN`
   - Absolute path to Python, e.g.:
      - Linux: `/usr/bin/python3`
      - Windows: `C:\Python312\python.exe`
   - Useful because Apache’s service account may not inherit your user PATH.

- `PW_CHECKER_DEBUG`
   - Set to `1` to include Python stderr in JSON responses.
   - Intended for local development only.

## Troubleshooting

### “Open in Browser” shows the XAMPP default page
- Your hostname is resolving, but Apache is serving the default vhost.
- Fix: confirm `Include conf/extra/httpd-vhosts.conf` is enabled and restart Apache.

### Python invocation fails (HTTP 500)
Common causes:
- Python isn’t installed, or Apache can’t see it via PATH.
- `requests` isn’t installed in the Python environment being used.

Fix:
- Set `PYTHON_BIN` to your Python executable’s absolute path and restart Apache.
- Install `requests` for that same interpreter.

### HIBP request fails
- The script calls `https://api.pwnedpasswords.com/range/{prefix}`.
- If you’re offline or behind restrictive proxy/firewall, requests may fail.

## Development notes

### Local-only by default
This tool is best used locally (or behind HTTPS). If you deploy it publicly, add:
- HTTPS/TLS
- Rate limiting
- Basic abuse protections (e.g., request size caps)

### Known limitations (intentional)
- No database, no user management.
- Output is a simple message string; could be extended to structured status codes.

## License
MIT
