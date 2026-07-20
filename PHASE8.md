# Phase 8 — Automated Signup & Subscription Checkout

Version `3.8.0-alpha8` connects the existing signup builder to Square-hosted recurring checkout and automated account/license provisioning.

## Workflow

1. A customer selects a plan through `[pdpsignup]`.
2. The server creates or connects a WordPress customer account.
3. Paid plans receive a Square-hosted subscription checkout page.
4. Square creates the recurring subscription after checkout.
5. The Square webhook matches the subscription to the customer and signup request.
6. The local subscription and active license are created automatically.
7. The customer can use the existing portal, license API, and plugin downloads.

## Setup

- Open **Party Desk Pro → Signup Automation** and enable the workflow.
- Open each paid plan and enter its **Square plan variation ID**.
- Confirm Square credentials and webhooks are configured.
- Place `[pdpsignup]` on the public signup page.

Free and trial plans are provisioned immediately without sending the customer to Square.
