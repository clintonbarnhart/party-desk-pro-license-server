# Phase 3 — License Management Engine

Version `3.3.0-alpha3` introduces the production-oriented license lifecycle layer.

## Included

- Dedicated activation and license-event database tables
- License activate, validate, deactivate, status, and update-check endpoints
- Website normalization and domain-limit enforcement
- License expiration, lifetime, suspended, revoked, and subscription-aware states
- Customer email and product matching
- API rate limiting and privacy-safe audit events
- Authorized website management in WordPress admin
- Security and validation activity timeline
- Bulk license lifecycle action handler
- Signed, short-lived update download URLs
- Backward-compatible `party-desk-license/v1` API routes

## API endpoints

- `POST /wp-json/party-desk-license/v1/activate`
- `POST /wp-json/party-desk-license/v1/validate`
- `POST /wp-json/party-desk-license/v1/deactivate`
- `POST /wp-json/party-desk-license/v1/update-check`
- `GET /wp-json/party-desk-license/v1/status`

Test on staging before production deployment.
