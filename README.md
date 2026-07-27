<p align="center">
  <img src="assets/image/logoS2S.png" alt="S2S — Scan to See" width="120" />
</p>

<h1 align="center">S2S — Scan to See</h1>
<p align="center">
  <strong>Digital Menu SaaS Platform for Coffee Shops, Restaurants, Spas &amp; Service Businesses</strong>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Symfony-6.4-black?logo=symfony&logoColor=white" />
  <img src="https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white" />
  <img src="https://img.shields.io/badge/MariaDB-10.4-003545?logo=mariadb&logoColor=white" />
  <img src="https://img.shields.io/badge/Doctrine_ORM-3.x-FC6A31" />
  <img src="https://img.shields.io/badge/Twig-3.x-bacf29?logo=twig&logoColor=black" />
  <img src="https://img.shields.io/badge/FastAPI-0.x-009688?logo=fastapi&logoColor=white" />
  <img src="https://img.shields.io/badge/PHPUnit-11.5-3776E6?logo=php&logoColor=white" />
  <img src="https://img.shields.io/badge/Tests-140_passing-brightgreen?logo=checkmarx&logoColor=white" />
  <img src="https://img.shields.io/badge/Coverage-100%25-brightgreen" />
  <img src="https://img.shields.io/badge/license-proprietary-red" />
</p>

---

## Table of Contents

- [Overview](#overview)
- [The Core Promise](#the-core-promise)
- [Features](#features)
  - [Owner Dashboard](#owner-dashboard)
  - [Menu Scanner (Beta)](#menu-scanner-beta)
  - [Admin Panel](#admin-panel)
  - [Customer-Facing Menu](#customer-facing-menu)
  - [Subscription Management](#subscription-management)
- [Tech Stack](#tech-stack)
- [Project Structure](#project-structure)
- [Getting Started](#getting-started)
  - [Prerequisites](#prerequisites)
  - [Installation](#installation)
  - [Database Setup](#database-setup)
  - [Creating Accounts](#creating-accounts)
- [Environment Variables](#environment-variables)
- [Running the App](#running-the-app)
  - [Symfony App](#symfony-app)
  - [Menu Scanner OCR Service](#menu-scanner-ocr-service)
- [Testing](#testing)
- [User Roles & Access](#user-roles--access)
- [Security](#security)
- [Contributing](#contributing)

---

## Overview

**S2S (Scan to See)** is a paid SaaS platform that lets businesses — coffee shops, restaurants, spas, salons, and similar venues — create and manage a fully customizable digital menu, accessible to customers instantly via a single, **permanent QR code**.

Owners build their menu once, choose a theme, add items with photos and prices, and print a QR code that goes on a table, wall, or counter. From that point on, the owner can update prices, descriptions, and availability at any time — **the printed QR code never needs to be replaced**, because it always points to the live, current version of the menu.

---

## The Core Promise

> The QR code is permanent. The content behind it is fully dynamic.

The QR code encodes a fixed URL (e.g. `s2s.app/m/blue-bean-cafe`) tied to a menu's permanent `slug` — not to its content. When an owner edits and publishes their menu, the content behind that slug updates, but the slug, URL, and QR code never change. Design once, print once, update forever.

---

## Features

### Owner Dashboard

| Feature | Description |
|---|---|
| **Multi-business management** | One account can own and manage multiple businesses independently |
| **Menu builder** | Create menus with categories and items — instant modal UI, zero-latency |
| **Draft / Publish workflow** | Edit freely in draft mode — customers only see what you explicitly publish |
| **Item management** | Name, price, description, availability toggle, photo upload |
| **Sold-out toggle** | Mark items unavailable without deleting them |
| **Theme presets** | Choose a visual theme per menu (modern, classic, minimal, …) |
| **Currency support** | Per-menu currency setting (default: TND) |
| **Logo & branding** | Upload a business logo per business |
| **QR Code generation** | Generate and download a permanent QR code for each menu |
| **Password reset** | Secure token-based password reset flow via email |
| **2FA setup** | TOTP-based two-factor authentication with backup codes |
| **Profile management** | Update name, email, and account preferences |

---

### Menu Scanner (Beta)

An AI-powered feature that extracts menu data from photos of handwritten or printed menus and imports it directly into the owner's digital menu — skipping manual data entry.

**Workflow:**

```
Owner opens a menu → clicks "Scan Menu (Beta)" → onboarding popup (4 slides)
→ Scanner Workspace → drops 1–3 images → clicks Scan
→ OCR pipeline extracts categories, items, prices
→ Owner reviews & edits extracted fields (all editable, confidence color-coded)
→ clicks "Save to Menu" → categories & items created directly in the DB
```

**Workspace features:**

| Feature | Description |
|---|---|
| **Drag-and-drop** | Drop up to 3 PNG/JPG images into the central panel |
| **Numbered image cards** | Each image gets a numbered badge (1 / 2 / 3) |
| **Scan animation** | Amber sweeping scan-line on the image during processing (1 image) |
| **Progress popup** | Blurred backdrop + progress bar for 2–3 image batches |
| **Per-image sidebar tabs** | Separate tab per image — edit each independently |
| **Confidence color coding** | Field borders: 🟢 ≥60% · 🟡 40–59% · 🟠 25–39% · 🔴 <25% or needs-review |
| **Per-category item scroll** | Each category block scrolls internally (max 190px) |
| **Drag-to-resize sidebar** | Grab the left edge of the sidebar to resize (280–650px) |
| **Add / remove items** | `+ Add item` button per category; `×` removes a row |
| **Remove categories** | `×` button in the category header removes the entire block |
| **Save to Menu** | POSTs edited data → creates `Category` + `Item` entities in target menu |

**Architecture:**

```
Browser → POST /owner/scanner/scan (Symfony)
        → MenuScannerClient (symfony/http-client)
        → FastAPI OCR pipeline (http://127.0.0.1:8001/scan-menu)
        → JSON { items, orphan_prices, quality_metrics }
        → Sidebar rendered with editable fields

Browser → POST /owner/scanner/save (Symfony)
        → OwnerMenuController::scannerSave()
        → Doctrine: Category + Item entities persisted
        → Redirect to menu show page
```

> **Note:** The OCR pipeline (`handwritten-menu-scanner/`) is a separate FastAPI service and must be running locally on port 8001. See [Menu Scanner OCR Service](#menu-scanner-ocr-service).

---

### Admin Panel

| Feature | Description |
|---|---|
| **Platform statistics** | Total owners, active owners, total businesses, total menus |
| **Owner management** | List, view detail, activate, deactivate, and delete owner accounts |
| **Account enforcement** | Deactivated owners are immediately blocked — active sessions are invalidated |
| **Audit trail** | All admin actions are traceable per owner |
| **Recent sign-ups** | Dashboard shows the 5 most recent owner registrations |

---

### Customer-Facing Menu

- Scan QR code — no app, no login, no friction
- Mobile-optimized menu page with the owner's chosen theme
- Browse categories and items, tap for full detail and photo
- Real-time availability — sold-out items clearly marked
- *(Multilingual support — planned for v2)*

---

### Subscription Management

| Feature | Description |
|---|---|
| **Tiered plans** | Basic (1+1), Premium (3+3), Pro (unlimited menus) |
| **Stripe integration** | Secure payment processing with webhook support |
| **Auto-swap feature** | Smart menu status swapping when limits are reached — no blocking |
| **Limit enforcement** | Automatic enforcement on plan downgrades with user-friendly selection UI |
| **Draft/Published limits** | Separate limits for draft and published menus per plan |
| **Daily subscription checks** | Symfony Scheduler runs daily expiry checks via `SubscriptionDailyCheckHandler` |
| **Flexible management** | Users can always edit within their plan capacity |

---

## Tech Stack

| Layer | Technology |
|---|---|
| **Framework** | [Symfony 6.4](https://symfony.com/) (LTS) |
| **Language** | PHP 8.2 |
| **Database** | MariaDB 10.4 via XAMPP |
| **ORM** | Doctrine ORM 3.x + Doctrine Migrations |
| **Templating** | Twig 3.x |
| **Frontend assets** | Symfony AssetMapper (no Webpack / no Node build step) |
| **JS components** | Stimulus (`@symfony/stimulus-bundle`) + Turbo Drive (`@hotwired/turbo`) |
| **Auth** | Symfony Security — form_login, remember-me (7 days), CSRF, 2FA (TOTP) |
| **2FA** | `scheb/2fa-bundle` v7 — TOTP + backup codes |
| **Payments** | Stripe (subscriptions, webhooks, `stripe/stripe-php`) |
| **HTTP Client** | `symfony/http-client` — for OCR pipeline proxy |
| **QR Codes** | `endroid/qr-code-bundle` v6 |
| **Email** | `symfony/mailer` + `symfony/google-mailer` |
| **Scheduler** | `symfony/scheduler` — daily subscription expiry checks |
| **OCR Pipeline** | FastAPI (Python) — `handwritten-menu-scanner/` (separate service, port 8001) |
| **Testing** | PHPUnit 11.5 — 140 tests, 337 assertions (100% pass rate) |
| **Typography** | Space Grotesk + DM Sans (Google Fonts) |
| **Dev server** | Symfony CLI (`symfony serve`) |

---

## Project Structure

```
my_project_directory/
├── assets/
│   ├── controllers/              # Stimulus JS controllers
│   ├── image/                    # Static images (logo, icons)
│   └── styles/                   # main.css (full design system)
├── config/
│   ├── packages/                 # Bundle configuration (doctrine, security, mailer, …)
│   ├── routes/                   # Additional route files
│   └── services.yaml             # Service wiring (SubscriptionService, MenuScannerClient, …)
├── migrations/                   # Doctrine database migrations
├── public/                       # Web root (index.php, uploaded images)
│   └── image/
│       ├── business/             # Business logos
│       └── items/                # Item photos
├── src/
│   ├── Command/                  # CLI commands
│   │   ├── CreateAdminCommand.php
│   │   └── SeedOwnerCommand.php
│   ├── Controller/
│   │   ├── AdminController.php               # Admin panel (owner management, stats)
│   │   ├── DashboardController.php           # Role-aware redirect
│   │   ├── OwnerController.php               # Owner dashboard home
│   │   ├── OwnerMenuController.php           # Menu CRUD + Scanner endpoints
│   │   ├── OwnerProfileController.php        # Profile & account settings
│   │   ├── OwnerQrController.php             # QR code generation & download
│   │   ├── PasswordResetController.php       # Forgot/reset password flow
│   │   ├── PublicMenuController.php          # Customer-facing menu page
│   │   ├── RegistrationController.php        # Owner sign-up
│   │   ├── SiteController.php                # Public pages (home, pricing, about)
│   │   ├── SubscriptionController.php        # Plan management, Stripe webhooks
│   │   ├── SubscriptionEnforcementController.php # Limit enforcement UI
│   │   └── TotpSetupController.php           # 2FA setup & backup codes
│   ├── Entity/
│   │   ├── Business.php          # Business (belongs to User, has Menus)
│   │   ├── Category.php          # Category (belongs to Menu, has Items)
│   │   ├── Item.php              # Menu item (name, price, photo, availability)
│   │   ├── Menu.php              # Menu (slug, theme, status, currency)
│   │   ├── Subscription.php      # Subscription plan + Stripe data
│   │   └── User.php              # Owner account (auth, 2FA, enforcement flags)
│   ├── EventSubscriber/
│   │   ├── Force2faSetupSubscriber.php       # Forces 2FA setup for ROLE_OWNER
│   │   ├── InactiveUserSubscriber.php        # Invalidates sessions of deactivated accounts
│   │   ├── LimitEnforcementSubscriber.php    # Blocks dashboard on plan limit breach
│   │   ├── ProfilerSubscriber.php            # Dev profiler helper
│   │   └── SubscriptionCheckSubscriber.php   # Checks subscription validity per-request
│   ├── Message/                  # Symfony Messenger messages
│   ├── MessageHandler/           # Messenger handlers (SubscriptionDailyCheckHandler)
│   ├── Repository/               # Doctrine repositories (one per entity)
│   ├── Scheduler/                # Symfony Scheduler (daily subscription task)
│   ├── Security/
│   │   └── UserChecker.php       # Blocks deactivated accounts at login
│   └── Service/
│       ├── ImageUploadService.php    # File validation, upload, deletion
│       ├── MenuScannerClient.php     # FastAPI OCR proxy (symfony/http-client)
│       └── SubscriptionService.php   # Plan limits, auto-swap, status transitions
├── templates/
│   ├── admin/                    # Admin panel templates
│   ├── emails/                   # Transactional email templates
│   ├── owner/
│   │   ├── layout.html.twig      # Owner dashboard shell
│   │   ├── menu/                 # Menu builder UI (show, categories, items)
│   │   ├── scanner/
│   │   │   ├── workspace.html.twig   # Menu Scanner workspace (full SPA-like UI)
│   │   │   └── privacy.html.twig     # Scanner data privacy notice
│   │   └── subscription/         # Pricing, plan enforcement UI
│   ├── public/                   # Customer-facing menu themes
│   ├── security/                 # Login, 2FA challenge pages
│   └── site/                     # Public pages (home, pricing, about)
└── tests/
    ├── Entity/                   # Entity unit tests (6 entities, 108 tests)
    └── Service/                  # Service unit tests (2 services, 32 tests)
```

---

## Getting Started

### Prerequisites

- PHP 8.2+
- [Composer](https://getcomposer.org/)
- [Symfony CLI](https://symfony.com/download)
- [XAMPP](https://www.apachefriends.org/) (or any MariaDB/MySQL server)
- Python 3.9+ + pip *(for the Menu Scanner OCR service only)*

### Installation

```bash
# 1. Clone the repository
git clone https://github.com/hamza030220/SCAN_TO_SEE.git
cd SCAN_TO_SEE/my_project_directory

# 2. Install PHP dependencies
composer install

# 3. Copy the environment file and configure it
cp .env .env.local
# Edit .env.local with your local database credentials and a fresh APP_SECRET
```

### Database Setup

```bash
# Create the database
php bin/console doctrine:database:create

# Run all migrations
php bin/console doctrine:migrations:migrate
```

### Creating Accounts

**Create an admin account:**
```bash
php bin/console app:create-admin admin@example.com YourPassword123@
```

**Seed a demo owner with businesses, menus, categories, and items:**
```bash
php bin/console app:seed-owner owner@example.com YourPassword123@
```

---

## Environment Variables

Copy `.env` to `.env.local` and configure the following (`.env.local` is **never committed**):

| Variable | Description | Example |
|---|---|---|
| `APP_ENV` | Application environment | `dev` |
| `APP_SECRET` | Cryptographic secret — generate with `php -r "echo bin2hex(random_bytes(16));"` | `a1b2c3...` |
| `DATABASE_URL` | Doctrine DSN for your database | `mysql://root:@127.0.0.1:3306/S2S?serverVersion=10.4.32-MariaDB&charset=utf8mb4` |
| `MESSENGER_TRANSPORT_DSN` | Message transport | `doctrine://default?auto_setup=0` |
| `MAILER_DSN` | Mailer transport | `null://null` (dev) |
| `MAILER_FROM` | From address for transactional emails | `noreply@scantosee.com` |
| `PUBLIC_BASE_URL` | Public URL used in QR codes | `https://your-domain.com` |
| `OCR_PIPELINE_URL` | Menu Scanner FastAPI service URL | `http://127.0.0.1:8001` |
| `STRIPE_SECRET_KEY` | Stripe secret key | `sk_test_...` |
| `STRIPE_PUBLISHABLE_KEY` | Stripe publishable key | `pk_test_...` |
| `STRIPE_WEBHOOK_SECRET` | Stripe webhook signing secret | `whsec_...` |
| `STRIPE_PRICE_*` | Stripe price IDs for each plan/period | `price_...` |

> **Never commit `.env.local`** — it contains your real secrets and is listed in `.gitignore`.

---

## Running the App

### Symfony App

```bash
# Start the Symfony development server
symfony serve

# Or with no TLS (simpler on some setups)
symfony serve --no-tls
```

The app will be available at **http://127.0.0.1:8000**.

> **Windows note:** Use foreground mode (`symfony serve`) rather than daemon mode (`symfony server:start -d`) to avoid a known Symfony CLI lock-file bug on Windows.

---

### Menu Scanner OCR Service

The Menu Scanner feature requires the FastAPI OCR pipeline to be running separately. It lives in the `handwritten-menu-scanner/` directory (sibling to `my_project_directory/`).

```bash
# Navigate to the OCR service directory
cd ../handwritten-menu-scanner

# Install Python dependencies (first time only)
pip install -r requirements.txt

# Start the FastAPI server on port 8001
uvicorn main:app --reload --host 0.0.0.0 --port 8001
```

**Test the OCR endpoint:**
```bash
curl -X POST http://localhost:8001/scan-menu \
  -F "image=@/path/to/your/menu.jpg" \
  -F "currency=TND"
```

**Expected response shape:**
```json
{
  "items": [
    { "name": "Americano", "price_value": 3.0, "price_raw": "3.00",
      "name_confidence": 0.87, "price_confidence": 0.95,
      "is_category_header": false, "needs_review": false, "currency": "TND" }
  ],
  "orphan_prices": [],
  "quality_metrics": { "warnings": [] }
}
```

> The Symfony app proxies all scan requests through `MenuScannerClient` — the browser never calls FastAPI directly.

---

## Testing

The application includes a comprehensive test suite covering all entities and core services.

### Running Tests

```bash
# Run all tests
php bin/phpunit

# Run specific test suite
php bin/phpunit tests/Entity/
php bin/phpunit tests/Service/

# Run a specific test file
php bin/phpunit tests/Service/SubscriptionServiceTest.php

# Run tests with coverage (requires Xdebug)
php bin/phpunit --coverage-html coverage/
```

### Test Coverage

| Test Suite | Tests | Assertions | Coverage |
|---|---|---|---|
| **Entity Tests** | 108 | 236 | 100% (6/6 entities) |
| **Service Tests** | 32 | 101 | 100% (2/2 services) |
| **Total** | **140** | **337** | **100% pass rate** |

#### Entity Tests

- ✅ **Business** (14 tests) — Ownership, timestamps, slug management
- ✅ **Category** (16 tests) — Nested structure, visibility, items collection
- ✅ **Item** (22 tests) — Pricing, availability, image handling
- ✅ **Menu** (22 tests) — Theme config, status, categories collection
- ✅ **Subscription** (18 tests) — Plans, limits, expiry calculations
- ✅ **User** (16 tests) — Auth, 2FA with backup codes, enforcement flag

#### Service Tests

- ✅ **SubscriptionService** (20 tests)
  - Active subscription validation
  - Menu counting (published/draft)
  - Status change permissions
  - Auto-swap functionality for plan limits

- ✅ **ImageUploadService** (32 tests)
  - File validation (MIME types, 2MB size limit)
  - Upload paths for logos, items, backgrounds
  - Slugification with internationalization
  - File deletion safety

---

## User Roles & Access

| Role | Access | Login Redirect |
|---|---|---|
| `ROLE_ADMIN` | Full admin panel at `/admin` | `/admin` |
| `ROLE_OWNER` | Owner dashboard at `/owner` | `/owner` |
| `ROLE_USER` | Base role (both inherit this) | `/dashboard` → redirects |

**Route access control:**

| Path | Required Role |
|---|---|
| `/admin/**` | `ROLE_ADMIN` |
| `/owner/**` | `ROLE_OWNER` |
| `/dashboard` | `ROLE_USER` |
| `/`, `/login`, `/sign-up`, `/pricing`, `/about` | Public |
| `/m/{slug}` | Public (customer menu) |

---

## Security

- **CSRF protection** on all state-changing forms (login, delete, toggle)
- **Password hashing** via Symfony's `UserPasswordHasher` (bcrypt)
- **Password reset** — secure time-limited tokens sent by email
- **Two-Factor Authentication (2FA)** — TOTP with backup codes via `scheb/2fa-bundle`
- **Force 2FA setup** — `Force2faSetupSubscriber` redirects owners to 2FA setup if not configured
- **UserChecker** blocks deactivated accounts at login
- **InactiveUserSubscriber** invalidates active sessions of accounts deactivated by an admin — enforced on every request
- **LimitEnforcementSubscriber** blocks dashboard access when subscription limits are exceeded
- **SubscriptionCheckSubscriber** validates subscription status on every authenticated request
- **Remember-me** tokens expire after 7 days
- **Role-based access control** enforced via `access_control` in `security.yaml` and `#[IsGranted]` attributes
- **File upload validation** — MIME type whitelist, 2MB size limit, secure path generation
- **Stripe webhook signature verification** for payment security
- **OCR proxy pattern** — the browser never calls the AI service directly; all requests route through the authenticated Symfony controller
- `.env` is excluded from version control — secrets stay local

---

## Contributing

This is a private project. For access or collaboration, contact the repository owner at [GitHub](https://github.com/hamza030220).

<h3 align="center">Made with love by Hamza Z ❤️</h3>
