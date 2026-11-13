# Findriva API

Laravel 13 modular monolith. See the [root README](../README.md) for setup and [docs/architecture.md](../docs/architecture.md) for how it fits together.

```bash
composer install && cp .env.example .env && php artisan key:generate
php artisan migrate --seed && php artisan serve
php artisan queue:work --queue=crawl,scan,default
php artisan test
```
