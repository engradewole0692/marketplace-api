# Deployment

Target environment: **AlmaLinux 8 VPS** with Nginx, PHP-FPM 8.3+, PostgreSQL, Redis, and Supervisor.

## Directory layout on server

```
/var/www/marketplace-ministers/
├── frontend/          # TanStack Start build (.output/)
└── backend/           # This Laravel application
```

## 1. System packages

```bash
sudo dnf install -y epel-release
sudo dnf install -y nginx postgresql-server postgresql redis supervisor
sudo dnf module install php:8.3/php-cli php-fpm php-pgsql php-mbstring php-xml php-curl php-zip php-redis php-opcache
```

Initialize PostgreSQL and create database/user:

```bash
sudo postgresql-setup --initdb
sudo systemctl enable --now postgresql
sudo -u postgres psql -c "CREATE USER marketplace WITH PASSWORD 'secure_password';"
sudo -u postgres psql -c "CREATE DATABASE marketplace_ministers OWNER marketplace;"
```

## 2. Deploy application code

```bash
cd /var/www/marketplace-ministers
git clone <repository-url> .
cd backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Production `.env` essentials:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://marketplaceministers.org

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=marketplace_ministers
DB_USERNAME=marketplace
DB_PASSWORD=secure_password

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=database

MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=noreply@marketplaceministers.org
```

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Set permissions:

```bash
sudo chown -R nginx:nginx storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

## 3. PHP-FPM

`/etc/php-fpm.d/www.conf` (adjust user/group to `nginx`):

```ini
user = nginx
group = nginx
listen = /run/php-fpm/www.sock
```

```bash
sudo systemctl enable --now php-fpm
```

## 4. Nginx

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name marketplaceministers.org;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name marketplaceministers.org;

    # ssl_certificate /etc/letsencrypt/live/marketplaceministers.org/fullchain.pem;
    # ssl_certificate_key /etc/letsencrypt/live/marketplaceministers.org/privkey.pem;

    root /var/www/marketplace-ministers/frontend/.output/public;
    index index.html;

    # Frontend (TanStack Start / Nitro)
    location / {
        try_files $uri $uri/ /index.html;
    }

    # Laravel API
    location /api {
        alias /var/www/marketplace-ministers/backend/public;
        try_files $uri $uri/ @api;

        location ~ \.php$ {
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME /var/www/marketplace-ministers/backend/public/index.php;
            fastcgi_pass unix:/run/php-fpm/www.sock;
        }
    }

    location @api {
        rewrite ^/api/(.*)$ /api/index.php?$query_string last;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

> **Note:** Adjust the Nginx `alias`/`rewrite` block to match your preferred Laravel public routing pattern. A common alternative is a dedicated `server` block or `location ^~ /api` proxying to `backend/public/index.php` with `SCRIPT_FILENAME` set correctly.

```bash
sudo nginx -t
sudo systemctl reload nginx
```

## 5. Supervisor (queue workers)

`/etc/supervisord.d/marketplace-ministers-worker.ini`:

```ini
[program:marketplace-ministers-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/marketplace-ministers/backend/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=nginx
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/marketplace-ministers/backend/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

## 6. Scheduler cron

```bash
sudo crontab -u nginx -e
```

```cron
* * * * * cd /var/www/marketplace-ministers/backend && php artisan schedule:run >> /dev/null 2>&1
```

## 7. Post-deploy verification

```bash
curl -s https://marketplaceministers.org/api/v1/health | jq
curl -s https://marketplaceministers.org/up
```

Expected health response: `"success": true`, `"data.status": "ok"`.

## 8. Zero-downtime deploy checklist

1. `git pull` on server
2. `composer install --no-dev --optimize-autoloader`
3. `php artisan migrate --force`
4. `php artisan config:cache && php artisan route:cache`
5. `sudo supervisorctl restart marketplace-ministers-worker:*`
6. Verify `/api/v1/health`

## Security hardening

- Set `APP_DEBUG=false` in production
- Restrict `storage/` and `.env` from web access
- Use TLS (Let's Encrypt / Certbot)
- Configure firewall: allow 80/443 only
- Keep PHP, PostgreSQL, and OS packages updated
