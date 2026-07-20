# Phase 2 — Square Billing Engine

Version `3.2.0-alpha2` adds the first production-capable Square billing services:

- Authenticated Square API client with sandbox/production endpoints.
- Connection testing against the Locations API.
- WordPress user to Square customer synchronization.
- Square subscription creation using a plan variation ID.
- Pause, resume, cancel, and refresh lifecycle actions.
- Webhook-driven local subscription status synchronization.
- Local event history and privacy-safe error logging.
- Admin controls for creating and managing Square subscriptions.

## Required setup

1. Save the Square access token, location ID, API version, and environment.
2. Use **Test Square Connection**.
3. Add the webhook URL to the matching Square application environment.
4. Save a Square plan variation ID in `_pdp_square_plan_variation_id` or enter it when creating a subscription.

Test all billing actions in Square Sandbox before production use.
