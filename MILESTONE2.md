# Party Desk Pro Server 3.0.0 Alpha 1 — Milestone 2

Implemented:
- Automatic dbDelta table installation and version tracking.
- Safe upgrade check on plugins_loaded.
- Subscription create/read/update helpers.
- Square customer upsert helper.
- Subscription event and webhook logging helpers.
- Database Health admin page and repair action.
- Existing plans, requests, licenses, settings, and customer portal remain intact.

This milestone provides the database layer only. It does not yet contact Square or process live webhooks.
