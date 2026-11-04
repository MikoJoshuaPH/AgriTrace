# LavaLust Framework

> A lightweight, fast PHP framework built for developers who want clean MVC architecture without unnecessary complexity or performance overhead.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-8892BF)](https://www.php.net/)
[![GitHub Stars](https://img.shields.io/github/stars/ronmarasigan/lavalust?style=flat)](https://github.com/ronmarasigan/lavalust/stargazers)

---

## Overview

**LavaLust** is an open-source PHP framework that follows the **MVC (Model–View–Controller)** architectural pattern. It is designed for developers who need a structured, maintainable, and scalable foundation — without the bloat of heavier modern frameworks.

Whether you are building a simple web application, a REST API, or a teaching project, LavaLust provides the right tools with minimal friction.

---

## Features

| Feature | Description |
|---|---|
| **MVC Architecture** | Clean separation of Models, Views, and Controllers for organized, maintainable code |
| **Built-in Routing** | Flexible URL routing that maps requests to controllers with minimal configuration |
| **Libraries & Helpers** | Reusable components for sessions, forms, validation, and database access |
| **Modular Design** | Scalable structure that supports clean organization as your application grows |
| **REST API Support** | First-class support for building RESTful APIs using LavaLust conventions |
| **ORM-like Models** | Simplified, readable database interaction without a heavy abstraction layer |

---

## Requirements

- PHP 8.1 or higher
- A web server with URL rewriting support (Apache `.htaccess` or Nginx config)
- Composer (optional, for dependency management)

---

## Composer Dependencies

The AgriTrace backend uses `phpmailer/phpmailer` with Gmail SMTP for transactional
email (sign-in codes, password resets, receipts, and support messages). Configure
these settings in the backend `.env` file:

```dotenv
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=your-gmail-address@gmail.com
MAIL_PASSWORD=
MAIL_FROM=your-gmail-address@gmail.com
MAIL_FROM_NAME=AgriTrace
```

Use your full Gmail address for `MAIL_USERNAME` and `MAIL_FROM`, and create a
Google App Password for `MAIL_PASSWORD` (your regular Google password will not
work). Keep credentials only in `.env`; do not commit them. Install backend
dependencies from the project root with:

```bash
composer install
```

LavaLust loads Composer's `vendor/autoload.php` automatically. QR code generation and Xendit payments use the project's own HTTP client and do
not require additional Composer packages. When `XENDIT_SECRET_KEY` and
`XENDIT_CALLBACK_TOKEN` are configured, checkout creates a Xendit invoice and
the server verifies the invoice status before marking an order paid. COD is
used only when online payments are not configured.

### Upstash Blob storage

The PHP API uses the AWS SDK for PHP to call Upstash Blob's S3-compatible API.
Create one **public** bucket in Upstash for profile/community images, QR images,
admin documents and database backups. Set its token only in `AgriTrace/.env`:

```dotenv
UPSTASH_BLOB_TOKEN=
```

Keep the token server-side; never put it in a `VITE_` variable or commit it.
Install dependencies with `composer install` and run the PHP API as usual. The
AWS SDK obtains fresh, short-lived S3 credentials from Upstash for storage calls;
all files in this bucket, including admin documents and database backups, are
publicly readable to anyone with their URLs. Admin-only API routes control
uploads and deletion but do not make those public object URLs private. Regular
public-file uploads retain the local storage fallback when the bucket is not
configured; admin uploads and backup writes require the bucket.

---

## Installation

**Clone the repository:**

```bash
git clone https://github.com/ronmarasigan/lavalust.git
cd lavalust
```

**Or download a release directly:**

```bash
wget https://github.com/ronmarasigan/lavalust/archive/refs/heads/main.zip
unzip main.zip
```

Configure your web server to point to the project root and ensure `mod_rewrite` (Apache) or equivalent is enabled.

---

## Quick Start

### 1. Define a Route

**File:** `app/config/routes.php`

```php
$router->get('/', 'Welcome::index');
$router->get('/about', 'Welcome::about');
$router->post('/users/store', 'Users::store');
```

### 2. Create a Controller

**File:** `app/controllers/Welcome.php`

```php
<?php

class Welcome extends Controller
{
    public function index()
    {
        $data['title'] = 'Home';
        $this->call->view('welcome', $data);
    }

    public function about()
    {
        $this->call->view('about');
    }
}
```

### 3. Create a View

**File:** `app/views/welcome.php`

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $title ?></title>
</head>
<body>
    <h1>Welcome to LavaLust Framework</h1>
    <p>Lightweight. Fast. MVC.</p>
</body>
</html>
```

### 4. Create a Model

**File:** `app/models/User_model.php`

```php
<?php

class User_model extends Model
{
    protected $table = 'users';

    public function getAll()
    {
        return $this->db->table($this->table)->get()->getResult();
    }

    public function findById(int $id)
    {
        return $this->db->table($this->table)
                        ->where('id', $id)
                        ->get()
    }
}
```

---

## Project Structure

```
lavalust/
├── app/
│   ├── config/          # Application configuration (database, routes, etc.)
│   ├── controllers/     # Controller classes
│   ├── models/          # Model classes
│   ├── views/           # View templates
│   └── libraries/       # Custom libraries and helpers
├── scheme/              # Core framework files (do not modify)
├── public/              # Publicly accessible entry point
│   └── index.php
└── runtime/            # Cache, logs, and uploads (must be writable)
```

---

## Configuration

### Database

**File:** `app/config/database.php`

```php
$database['main'] = array(
    'driver'	=> getenv('DB_DRIVER') ?: '',
    'hostname'	=> getenv('DB_HOST') ?: '',
    'port'		=> getenv('DB_PORT') ?: '',
    'username'	=> getenv('DB_USER') ?: '',
    'password'	=> getenv('DB_PASSWORD') ?: '',
    'database'	=> getenv('DB_NAME') ?: '',
    'charset'	=> getenv('DB_CHARSET') ?: '',
    'dbprefix'	=> getenv('DB_PREFIX') ?: '',
    // Optional for SQLite
    'path'      => ''
);
```

### Base URL

**File:** `app/config/config.php`

```php
$config['base_url'] = 'http://localhost:3000/';
```

---

## AgriTrace farmer applications

The buyer-to-farmer workflow is implemented with the LavaLust application
extension points:

- `POST /api/farmer-applications` lets a buyer (`collector` role) submit farm
  information.
- `GET /api/farmer-applications/mine` returns the buyer's latest application.
- `GET /api/admin/farmer-applications?status=pending` lists applications for
  administrators.
- `PUT /api/admin/farmer-applications/{id}` approves or rejects an application.

Run the normal LavaLust migration endpoint after deploying
`app/migrations/017_farmer_applications.php`. Submission records the initial
pending event, while approval changes the existing user role to `farmer`,
creates the registered farm, and records the review history in one database
transaction. The standalone `DATABASE.sql` schema contains the same workflow
tables for fresh database setup. Farm addresses remain private; public farm
displays should use the location fields intended for marketplace/profile
presentation.

---

## Marketplace, fulfillment and scheduled operations

Migration `018_marketplace_fulfillment_extensions.php` adds catalog aliases,
inventory movements, batch allocations, farmer payment settings, payout
tracking, report history, and the metadata needed to connect listings to farms
and catalog products.

Migration `019_cod_checkout.php` adds the payment method recorded on each order.
Checkout provides both `payment_method=xendit` and `payment_method=cod`.
Online payment requires a configured Xendit key; COD remains available
independently and is paid upon delivery.

Configure the Xendit invoice callback URL as:

`https://<your-api-host>/api/payments/xendit/webhook`

The webhook must send the configured `XENDIT_CALLBACK_TOKEN` in the
`X-CALLBACK-TOKEN` header. The API fetches the invoice from Xendit before
changing payment or inventory state, so frontend redirects alone never mark an
order paid.

New API surfaces include:

- `GET /api/search?q=...` for related Marketplace products, FarmerHub profiles,
  and public registered farm details
- `GET /api/catalog/search?name=...`
- `POST /api/catalog/{id}/aliases` (administrator only)
- `GET|POST /api/farmer/payment-settings`
- `GET /api/farmer/payouts`
- `POST /api/orders/{id}/allocate-batches`
- `PUT /api/orders/{id}/status`
- Marketplace `search`, `category`, `unit`, `min_price`, `max_price`, and
  `sort` query parameters

Media uploads are stored under `users/{internal-user-id}/YYYY-MM-DD/` with
randomized filenames. The internal ID is used instead of the display name to
avoid collisions and leaking personal names in storage paths.

Database backups are created manually from the administrator backup controller
or automatically by the server-side scheduler. On Windows, run
`scripts\install-cloud-backup-task.ps1` from PowerShell in the backend project
directory to register a daily task at **12:00 PM local time**. Pass
`-PhpPath "C:\path\to\php.exe"` if PHP is not on `PATH`. The task runs while the
current Windows user is logged in; if the computer is asleep or off at noon,
Task Scheduler runs it when available. Run history is appended to
`runtime\logs\backup-scheduler.log`.

The task uses the CLI-only `/api/admin/backups/scheduled` entry point; it does
not expose an unauthenticated web backup operation. For Linux deployments,
schedule `php /path/to/AgriTrace/public/index.php api/admin/backups/scheduled`
with cron at `0 12 * * *` in the server's local timezone. The cloud storage
token must remain in the backend `.env` file, and the task host needs database
access and the PHP dependencies installed.

Administrator PDF reports accept `start_date=YYYY-MM-DD` and
`end_date=YYYY-MM-DD` query parameters and include the selected period,
record count, and a formal letter addressed to the Administrator. The
administrator dashboard asks for the same inclusive date range before each
CSV or PDF export; leaving both dates blank includes all loaded records for
CSV and all available records for PDF.

---

## Building a REST API

LavaLust supports REST API development out of the box. Controllers can return JSON responses for API endpoints.

```php
<?php

class Api extends Controller
{
    $this->call->library('api');

    public function users()
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt(); 

        $this->call->model('User_model');
        $users = $this->User_model->getAll();

        $this->api->respond(['data' => $users]);
    }
}
```

Route definition:

```php
$router->get('/api/users', 'Api::users');
```

---

## Philosophy

LavaLust is built on a single principle: **minimal core, maximum control.**

Modern frameworks often add layers of abstraction that benefit large enterprise teams but get in the way of developers who want to understand exactly what their code is doing. LavaLust provides structure and utilities without hiding the underlying logic — making it an excellent choice for:

- **Rapid prototyping** — Get an application running in minutes
- **Learning MVC** — Understand how each architectural layer works
- **Lightweight production apps** — Deploy without dragging in unused dependencies
- **Teaching PHP development** — Clear conventions, readable source code

---

## Documentation

Full documentation is available at **[https://lavalust.netlify.app](https://lavalust.netlify.app)**

Topics covered include:

- Installation and server configuration
- Routing: static, dynamic, and grouped routes
- Controllers and request handling
- Models and query builder
- Views, layouts, and partials
- Built-in libraries (sessions, form validation, file upload)
- Helper functions
- REST API development
- Security best practices

---

## Contributing

Contributions are welcome. To contribute:

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/your-feature-name`
3. Commit your changes: `git commit -m "Add your feature description"`
4. Push to your branch: `git push origin feature/your-feature-name`
5. Open a pull request against `main`

Please ensure your code follows the existing style conventions and includes relevant documentation or comments where appropriate.

---

## Roadmap

- [ ] CLI tool for generating controllers, models, and migrations
- [ ] Middleware support
- [ ] Improved query builder with relationship support
- [ ] Enhanced error handling and debugging tools

---

## License

LavaLust Framework is open-source software licensed under the **[MIT License](https://opensource.org/licenses/MIT)**.

---

## Links

- **GitHub Repository:** [https://github.com/ronmarasigan/lavalust](https://github.com/ronmarasigan/lavalust)
- **Documentation:** [https://lavalust.netlify.app](https://lavalust.netlify.app)
- **Report an Issue:** [https://github.com/ronmarasigan/lavalust/issues](https://github.com/ronmarasigan/lavalust/issues)