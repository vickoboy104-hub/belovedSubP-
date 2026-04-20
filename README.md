# BelovedSubP

Laravel VTU platform with wallet funding, Flutterwave integration, admin tools, guides, and Android app assets.

## GitHub Codespaces

This repo is set up so you can work from GitHub instead of your local machine.

When a Codespace is created, it now:

- copies `.env.example` to `.env` if needed
- creates `database/database.sqlite`
- installs Composer and NPM dependencies
- generates the Laravel app key
- creates the storage symlink
- runs database migrations
- clears cached Laravel config/routes/views
- copies matching Codespaces secrets into `.env` automatically

### Create your Codespace

1. Open the repository on GitHub.
2. Click `Code`.
3. Open the `Codespaces` tab.
4. Click `Create codespace on main`.

### Add repository or Codespaces secrets

Add these in GitHub before starting the Codespace if you want the app to boot with your real integration settings:

```env
APP_URL=
DB_CONNECTION=
DB_HOST=
DB_PORT=
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
MAIL_MAILER=
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME=
FLUTTERWAVE_PUBLIC_KEY=
FLUTTERWAVE_SECRET_KEY=
FLUTTERWAVE_ENCRYPTION_KEY=
FLUTTERWAVE_SECRET_HASH=
FLUTTERWAVE_BASE_URL=https://api.flutterwave.com
FLUTTERWAVE_FIXED_ACCOUNT_BVN=
GSUBZ_API_KEY=
GSUBZ_BASE_URL=https://api.gsubz.com
ALT_PROVIDER_API_KEY=
ALT_PROVIDER_BASE_URL=
NIN_API_KEY=
NIN_BASE_URL=https://confirmident.com.ng/api
NIN_PRINT_ENDPOINT=
NIN_REPORTS_ENDPOINT=
NIN_VALIDATION_ENDPOINT=
BVN_API_KEY=
BVN_BASE_URL=https://confirmident.com.ng/api
BVN_VERIFY_ENDPOINT=/bvn_search
BVN_RETRIEVE_PHONE_ENDPOINT=
BVN_RETRIEVE_BMS_ENDPOINT=
BVN_PRINT_ENDPOINT=
```

Any secret with the same variable name is synced into `.env` during Codespace setup.

### Start the app in Codespaces

Open two terminals:

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

```bash
npm run dev -- --host 0.0.0.0 --port 5173
```

Then open the forwarded port for `8000`.

### Daily workflow

```bash
git pull
php artisan optimize:clear
php artisan test
```

Make your changes in Codespaces, commit there, then push back to GitHub.

## Notes

- Codespaces replaces your local development box, not your production server.
- If you need webhook testing from a Codespace, use the public forwarded URL for port `8000`.
- Real payment testing should use test credentials or a dedicated safe environment.
