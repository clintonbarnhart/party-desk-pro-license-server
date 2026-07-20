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
