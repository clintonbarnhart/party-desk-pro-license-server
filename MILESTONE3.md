# Party Desk Pro Server 3.0.0 Alpha 1 — Milestone 3

## Added
- Professional Subscriptions admin page with status summaries and filtering.
- Dedicated Square Subscriptions settings page for Sandbox/Production credentials.
- Public REST receiver at `/wp-json/party-desk-pro/v3/square/webhook`.
- HMAC-SHA256 Square signature validation using the exact notification URL and raw request body.
- Duplicate-safe webhook storage through the Milestone 2 database layer.
- Professional Webhook Logs page with payload inspection, test logging, and clear-log controls.
- Subscription event logging for validated Square notifications.

## Scope boundary
This release receives, validates, and records webhook events. It does not yet call Square APIs, create customers, create catalog subscription plans, collect cards, or start recurring subscriptions. Those functions belong to Milestone 4.
