# Order Management System

A minimal Laravel application with a Blade page displaying Hello World, styled with Tailwind CSS 4.

Laravel Boost is configured for development. MySQL stores the packaging order schema: customers, orders, order items, and payments.

## Development

Install PHP and frontend dependencies if they are not already present:

~~~sh
composer install
npm install --ignore-scripts
~~~

For a new checkout, copy .env.example to .env, configure your MySQL credentials, generate the application key, and create the application tables:

~~~sh
php artisan key:generate
php artisan migrate
~~~

Start Laravel and Vite together:

~~~sh
composer run dev
~~~

Stop both processes with Ctrl+C. Restart this command after changing .env.

## Main files

- routes/web.php defines the home route.
- resources/views/hello.blade.php contains the page.
- resources/css/app.css imports Tailwind and scans Blade views.
- vite.config.js configures the stylesheet build and browser refresh.
- database/migrations contains the four order management migrations.

Sessions and cache use files. Jobs run synchronously, so development does not start a queue worker.

## Render testing deployment

This repository includes a Render Blueprint for a free Docker web service and a free PostgreSQL database, both in Singapore. The Docker image uses PHP 8.5 and Apache, installs production Composer dependencies, and builds the Vite assets.

1. Merge the deployment configuration into `main`.
2. In Render, select **New → Blueprint** and connect this GitHub repository.
3. Select the `main` branch and use `render.yaml` as the Blueprint file.
4. Confirm that `oms-testing` and `oms-testing-db` both use the **Free** plan, then create the Blueprint.
5. Wait for both services to deploy, then open the web service's public URL and visit `/orders` to test the app.

The Blueprint generates an encryption secret and supplies the database's internal connection string as `DB_URL`. The startup script converts the generated secret into Laravel's `APP_KEY` format and derives `APP_URL` from Render's public URL. Keep the encryption secret stable across redeploys.

Database migrations and Laravel cache warmup run when the container starts because free web services do not support pre-deploy commands. Apache serves only `public/` and listens on Render's `PORT`. Render monitors `/up`, and the application trusts the proxy's HTTPS headers. Sessions use the PostgreSQL `sessions` table, while temporary cache files stay inside the container.

Receipt uploads use `PAYMENT_RECEIPT_STORAGE=local` and are stored privately in `storage/app/private/payment-receipts`. Supabase credentials are not required. Receipts still download through the application's authorized payment receipt route and accept JPG, PNG, or PDF files up to 5 MB.

For an existing Render web service, set `PAYMENT_RECEIPT_STORAGE` to `local`, remove the `SUPABASE_URL`, `SUPABASE_SECRET_KEY`, and `SUPABASE_STORAGE_BUCKET` environment variables, and redeploy with this configuration.

The free web service sleeps after 15 minutes of inactivity. Its filesystem is temporary: uploaded receipts can be lost when the service restarts, redeploys, or spins down. Render does not include free persistent storage for uploads. The free PostgreSQL database expires after 30 days and has a 1 GB limit. This configuration is for testing; access to orders requires an OMS account. The Blueprint does not copy your local MySQL data or seed the remote database.

When persistent receipt storage is needed, upgrade the web service to the paid Starter plan and add a persistent disk mounted at `/var/www/html/storage/app/private`. Keep `PAYMENT_RECEIPT_STORAGE=local`. For Blueprint-managed services, change the web service's `plan` to `starter` and add this disk configuration under the web service:

~~~yaml
disk:
  name: payment-receipts
  mountPath: /var/www/html/storage/app/private
  sizeGB: 5
~~~

The startup script prepares the upload directory for Apache's `www-data` user, including when a disk is mounted there. Only files written after the disk is attached are stored on that disk; adding a disk does not recover receipts lost on the free plan. The committed Blueprint stays free and does not provision a disk.

## Verification

~~~sh
npm run build
php artisan test --compact
~~~

The tests cover the homepage, multiple order items and payments, foreign-key protections, item deletion, and migration rollback/reapplication. Database tests use SQLite in memory.

## Database and Boost

MySQL connection settings are in .env. The four application tables are customers, orders, order_items, and payments. Laravel's migrations table tracks which schema changes have been applied.

Record future schema changes in new migrations so existing databases receive those changes when you run php artisan migrate. For example:

~~~sh
php artisan make:migration add_email_to_customers_table --table=customers
~~~

Define the change and its rollback in the new migration, then run php artisan migrate. Keep applied migration files as the database's version history.

Use Laravel Boost through your coding assistant to inspect the database, search documentation, and read application errors.
