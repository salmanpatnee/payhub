# Deploying PayHub on shared hosting (Hostinger / cPanel)

PayHub can run in two layouts. The same code and the same build work for both. There is no setting to switch.

| Layout | Example address | Folder name in the address |
|---|---|---|
| Subdomain | `https://paynow.example.com/` | none |
| Folder | `https://example.com/payhub/` | `/payhub` |

How it works: Laravel works out the folder name from the request. At the top of a domain it is empty, so nothing changes. In a folder it is `/payhub`, and the front end adds it to links, requests, script files and fonts.

**The one rule for both layouts:** the web server must run PayHub's `public/index.php` directly. Never point `/payhub` at the project root with a rewrite rule. That makes Laravel miss the folder name, and the page goes blank (script files return an HTML 404, shown as "MIME type text/html" in the browser console).

Paths below use Hostinger as the example. Replace `u700766929`, the domain and the folder names with your own. On Hostinger, use the PHP 8.3 command line at `/opt/alt/php83/usr/bin/php`.

---

## Part 1: Subdomain deployment

Example: `https://paynow.example.com/`, with the project in `~/domains/example.com/public_html/paynow`.

1. **Clone the project** into the subdomain's folder and check out `master`.
   ```bash
   cd ~/domains/example.com/public_html
   git clone <repo-url> paynow
   cd paynow
   ```
2. **Install packages.**
   ```bash
   /opt/alt/php83/usr/bin/php $(which composer) install --no-dev --optimize-autoloader
   ```
3. **Create the `.env` file.** Copy `.env.example` to `.env` and fill in the database, keys and mail settings. Set:
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://paynow.example.com
   SESSION_DOMAIN=null
   ```
   Then run `php artisan key:generate` (only on a brand new install).
4. **Set up the database.**
   ```bash
   /opt/alt/php83/usr/bin/php artisan migrate --force
   ```
5. **Send web traffic to `public/`.** Create `.htaccess` in the project folder (`paynow`) with:
   ```apache
   <IfModule mod_rewrite.c>
     RewriteEngine On
     RewriteRule ^(.*)$ public/$1 [L]
   </IfModule>
   ```
   This works here because the address has no folder name, so Laravel needs no extra information.
6. **Clear caches and restart PHP.**
   ```bash
   /opt/alt/php83/usr/bin/php artisan config:clear
   /opt/alt/php83/usr/bin/php artisan view:clear
   ```
   In hPanel, open **Advanced → PHP Configuration** and click **Save**. This clears opcache.
7. Open `https://paynow.example.com/login`.

---

## Part 2: Folder deployment

Example: `https://example.com/payhub/`, with the project outside the web folder at `~/payhub-app`.

The idea: keep the project outside the web folder, then make `public_html/payhub` a symlink to the project's `public` folder. The web server then runs `public/index.php` from inside `/payhub`, as the rule above requires. Keeping the code outside the web folder also means `.env` and the code cannot be reached from the internet.

1. **Clone the project outside the web folder**, and check out `master`.
   ```bash
   cd ~
   git clone <repo-url> payhub-app
   cd payhub-app
   ```
   If the project is already inside `public_html/payhub` (the layout that gives a blank page), move it:
   ```bash
   cd ~/domains/example.com/public_html
   mv payhub ~/payhub-app
   rm ~/payhub-app/.htaccess      # the old rewrite file, not needed any more
   ```
2. **Install packages** (`composer install` as in Part 1, step 2).
3. **Create the `.env` file.** Copy `.env.example`, or copy the `.env` of another working site and change the URL:
   ```bash
   cp <other-site>/.env ~/payhub-app/.env
   sed -i 's#^APP_URL=.*#APP_URL=https://example.com/payhub#' ~/payhub-app/.env
   ```
   Check that these are set:
   ```
   APP_URL=https://example.com/payhub
   SESSION_DOMAIN=null
   ```
   `ASSET_URL` is not needed. Add the `APP_URL` line if it is missing.
   Pointing two sites at the same database is allowed, but both then share all data. Use a separate database if the sites must not share.
4. **Link the web folder to `public/`.**
   ```bash
   cd ~/domains/example.com/public_html
   ln -s ~/payhub-app/public payhub
   ls -la payhub     # should show: payhub -> /home/.../payhub-app/public
   ```
   Make sure there is no old `payhub` folder or custom `.htaccess` rewrite left in its place.
5. **Set up the database** (`php artisan migrate --force`).
6. **Clear caches and restart PHP** as in Part 1, step 6. Also run `route:clear` the first time.
7. Open `https://example.com/payhub/login`.
8. **Webhook addresses must include the folder**, for example `https://example.com/payhub/webhook/authorizenet/{account id}`. Do this for every payment provider you point at the folder site.

**If the host does not allow symlinks:** copy everything inside `public/` into `public_html/payhub/`. Edit that `index.php` so the two `__DIR__.'/../...'` paths point at `~/payhub-app`. Copy `public/build` again after every deploy.

---

## Updating a live site (both layouts)

```bash
cd <project folder>            # paynow, or ~/payhub-app
git pull origin master
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan view:clear
```

Then restart PHP in hPanel (**Advanced → PHP Configuration → Save**). The built front end files are committed to the repo, so no `npm` step is needed on the server.

## If something goes wrong

| What you see | Likely cause | Fix |
|---|---|---|
| Blank page on the folder site; console shows "MIME type text/html" and a `/not-found/` address | `/payhub` is pointed at the project root by a rewrite rule, or the site runs an old build | Use the symlink (Part 2, step 4), remove the rewrite file, pull `master` |
| Login page loads but links go to the top of the domain | `APP_URL` is wrong or the config is cached | Fix `APP_URL`, run `config:clear` |
| "No application encryption key" or database errors | `.env` is missing or incomplete (a new `.env` with one line is a common cause) | Copy a full `.env` (Part 2, step 3) |
| Changes do not show after a pull | opcache or cached views | `view:clear`, then restart PHP in hPanel |
| Logged out right after login | `SESSION_DOMAIN` is set to another domain | Set `SESSION_DOMAIN=null` |

For how the front end handles the folder name, see `docs/agent.md` → "Serving PayHub from a folder".
