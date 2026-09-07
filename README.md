<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Deployment — `public/web.config` is NOT in this repository

The platform runs behind **IIS (Plesk on Windows)**, and IIS needs a
`public/web.config` that this repository deliberately does not carry: it is
listed in `.gitignore`, so it exists only on the server.

**If the project moves to a new domain, host or server, that file has to be
copied across by hand.** A fresh `git clone` plus deploy will not produce it,
nothing in the build creates it, and the failures it causes do not look like a
missing config file:

| Missing from `web.config` | What breaks |
|---|---|
| WebDAV module + handler removed | `PUT`, `PATCH` and `DELETE` return 405 — WebDAV claims those verbs before PHP sees them, so every update and delete endpoint fails while `GET`/`POST` look fine |
| CORS preflight outbound rules | IIS answers `OPTIONS` itself without the `Access-Control-Allow-*` headers, so the browser blocks the real request and the SPA reports a network error on every write |
| `maxAllowedContentLength` (2 GB) | Large video and PDF uploads fail with a 404-shaped IIS error, not a Laravel validation message |
| `TRACE` denied | Method still reachable |

The last known-good version is in git history — recover it with:

```bash
git show 4a922b3^:public/web.config > public/web.config
```

It carries no hostnames, so the same file works on any domain; it is untracked
because the server, not the repository, owns it. After copying it, confirm the
response includes the `X-Elameed-Webconfig` header — that header exists purely
to prove the file is live.

Also remember, on any new environment:

```bash
php artisan migrate --force && php artisan rbac:sync
```

plus a queue worker and the scheduler (`php artisan queue:work`,
`php artisan schedule:work`) — notifications are delivered on the queue, and
scheduled messages and the daily billing reminders come from the scheduler.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
