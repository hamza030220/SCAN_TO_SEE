<p align="center">
  <img src="assets/image/logoS2S.png" alt="S2S — Scan to See" width="120" />
</p>

<h1 align="center">S2S — Scan to See</h1>
<p align="center">
  <strong>Digital Menu SaaS Platform for Coffee Shops, Restaurants, Spas & Service Businesses</strong>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Symfony-6.4-black?logo=symfony&logoColor=white" />
  <img src="https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white" />
  <img src="https://img.shields.io/badge/MariaDB-10.4-003545?logo=mariadb&logoColor=white" />
  <img src="https://img.shields.io/badge/Doctrine_ORM-3.x-FC6A31" />
  <img src="https://img.shields.io/badge/Twig-3.x-bacf29?logo=twig&logoColor=black" />
  <img src="https://img.shields.io/badge/license-proprietary-red" />
</p>

---

## Table of Contents

- [Overview](#overview)
- [The Core Promise](#the-core-promise)
- [Features](#features)
  - [Owner Dashboard](#owner-dashboard)
  - [Admin Panel](#admin-panel)
  - [Customer-Facing Menu](#customer-facing-menu)
- [Tech Stack](#tech-stack)
- [Project Structure](#project-structure)
- [Getting Started](#getting-started)
  - [Prerequisites](#prerequisites)
  - [Installation](#installation)
  - [Database Setup](#database-setup)
  - [Creating Accounts](#creating-accounts)
- [Environment Variables](#environment-variables)
- [Running the App](#running-the-app)
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
| **Menu builder** | Create menus with categories, subcategories, and items |
| **Draft / Publish** | Edit freely in draft mode — customers only see what you publish |
| **Instant modal UI** | All CRUD forms open as instant modal popups (pre-fetched, zero-latency) |
| **Item management** | Name, price, short description, long description, availability toggle |
| **Sold-out toggle** | Mark items unavailable without deleting them |
| **Theme presets** | Choose a visual theme for each menu (modern, classic, minimal, …) |
| **Currency support** | Per-menu currency setting (default: TND) |
| **Logo & branding** | Upload a business logo per business |

### Admin Panel

| Feature | Description |
|---|---|
| **Platform statistics** | Total owners, active owners, total businesses, total menus |
| **Owner management** | List, view detail, activate, deactivate, and delete owner accounts |
| **Account enforcement** | Deactivated owners are immediately blocked — active sessions are invalidated |
| **Audit trail** | All admin actions are traceable per owner |
| **Recent sign-ups** | Dashboard shows the 5 most recent owner registrations |

### Customer-Facing Menu

- Scan QR code — no app, no login, no friction
- Mobile-optimized menu page with the owner's chosen theme
- Browse categories and items, tap for full detail and photo
- Real-time availability — sold-out items clearly marked
- *(Multilingual support — planned for v2)*

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
| **Auth** | Symfony Security — form_login, remember-me (7 days), CSRF protection |
| **Typography** | Space Grotesk + DM Sans (via Google Fonts) |
| **Dev server** | Symfony CLI (`symfony serve --no-tls`) |

---

## Project Structure

```
my_project_directory/
├── assets/
│   ├── controllers/          # Stimulus JS controllers
│   ├── image/                # Static images (logo, etc.)
│   └── styles/               # CSS files
├── config/
│   ├── packages/             # Symfony bundle configuration
│   └── routes/               # Additional route files
├── migrations/               # Doctrine database migrations
├── public/                   # Web root (index.php)
├── src/
│   ├── Command/              # CLI commands (create-admin, seed-owner)
│   ├── Controller/           # HTTP controllers
│   │   ├── AdminController.php
│   │   ├── DashboardController.php
│   │   ├── OwnerController.php
│   │   ├── OwnerMenuController.php
│   │   ├── OwnerProfileController.php
│   │   ├── RegistrationController.php
│   │   └── SiteController.php
│   ├── Entity/               # Doctrine entities (User, Business, Menu, Category, Item)
│   ├── EventSubscriber/      # Kernel event subscribers (inactive user enforcement)
│   ├── Repository/           # Doctrine repositories
│   └── Security/             # Custom UserChecker (blocks deactivated logins)
├── templates/
│   ├── admin/                # Admin panel templates
│   ├── owner/                # Owner dashboard + menu builder templates
│   │   └── menu/
│   └── site/                 # Public pages (home, login, register, pricing)
└── tests/
```

---

## Getting Started

### Prerequisites

- PHP 8.2+
- [Composer](https://getcomposer.org/)
- [Symfony CLI](https://symfony.com/download)
- [XAMPP](https://www.apachefriends.org/) (or any MariaDB/MySQL server)

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

Copy `.env` to `.env.local` and set the following (`.env.local` is **never committed**):

| Variable | Description | Example |
|---|---|---|
| `APP_ENV` | Application environment | `dev` |
| `APP_SECRET` | Cryptographic secret — generate with `php -r "echo bin2hex(random_bytes(16));"` | `a1b2c3...` |
| `DATABASE_URL` | Doctrine DSN for your database | `mysql://root:@127.0.0.1:3306/S2S?serverVersion=10.4.32-MariaDB&charset=utf8mb4` |
| `MESSENGER_TRANSPORT_DSN` | Message transport (Doctrine by default) | `doctrine://default?auto_setup=0` |
| `MAILER_DSN` | Mailer transport | `null://null` (dev) |

> **Never commit `.env.local`** — it contains your real secrets and is listed in `.gitignore`.

---

## Running the App

```bash
# Start the development server (foreground — recommended on Windows)
symfony serve --no-tls

# Or on a specific port
symfony serve --no-tls --port=8001
```

The app will be available at **http://127.0.0.1:8000** (or the port you specified).

> **Windows note:** Use foreground mode (`symfony serve`) rather than daemon mode (`symfony server:start -d`) to avoid a known Symfony CLI lock-file bug on Windows.

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

---

## Security

- **CSRF protection** on all state-changing forms (login, delete, toggle)
- **Password hashing** via Symfony's `UserPasswordHasher` (bcrypt)
- **UserChecker** blocks deactivated accounts at login
- **InactiveUserSubscriber** invalidates active sessions of accounts deactivated by an admin — enforced on every request
- **Remember-me** tokens expire after 7 days
- **Role-based access control** enforced via `access_control` in `security.yaml` and `#[IsGranted]` attributes on controllers
- `.env` is excluded from version control — secrets stay local

---

## Contributing

This is a private project. For access or collaboration, contact the repository owner at [GitHub](https://github.com/hamza030220).
