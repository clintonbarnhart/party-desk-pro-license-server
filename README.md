# Party Desk Pro License Server

Commercial WordPress licensing, subscription, customer portal, and Square billing platform for Party Desk Pro.

## Current status

This repository is initialized from the verified `3.0.0-alpha1-milestone3` codebase. The current implementation includes:

- WordPress plugin bootstrap
- Database installation and repair routines
- Subscription, event, webhook-log, Square-customer, and sync tables
- Square subscription settings
- REST webhook endpoint
- HMAC SHA-256 webhook signature validation
- Subscription and webhook admin screens

## Branch strategy

- `main` — stable, reviewable project history
- `develop` — active development integration branch
- `feature/*` — individual implementation branches

## Development roadmap

1. Harden plugin bootstrap and database migrations.
2. Add a production Square API client and connection testing.
3. Implement customer and catalog synchronization.
4. Implement subscription creation, cancellation, pause, and resume.
5. Expand webhook event processing and reconciliation.
6. Build the customer portal and license validation API.
7. Add automated tests, packaging, and release workflows.

## Safety

This is alpha software. Test on a staging WordPress installation before production deployment.


## Phase 1 Core Framework

The `3.1.0-alpha1` phase adds a service bootstrap, autoloading, repeatable migrations, typed settings, privacy-safe logging, security helpers, and a System & Diagnostics admin screen. See `PHASE1.md`.

## Phase 2 Square Billing Engine

The `3.2.0-alpha2` release adds a real Square API client, connection testing, customer synchronization, subscription creation and lifecycle controls, plus webhook-driven reconciliation. See `PHASE2.md`.

## Phase 3 License Management Engine

The `3.3.0-alpha3` phase adds durable website activation records, license lifecycle validation, domain-limit enforcement, API audit events, update-check responses, and a License Activity admin screen. See `PHASE3.md`.


## Phase 4 Operations & Portal

The `3.4.0-alpha4` phase adds a professional license operations dashboard, advanced filtering, security timeline, and customer-facing authorized website and activity views. See `PHASE4.md`.


## Phase 5 Commercial Product Delivery

The `3.5.0-alpha5` phase adds multi-product management, stable/beta release channels, license-gated update metadata, signed ZIP downloads, and a product delivery dashboard. See `PHASE5.md`.
