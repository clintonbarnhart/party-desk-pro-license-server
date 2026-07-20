# Phase 5 — Commercial Product Delivery

Version `3.5.0-alpha5` adds the first production-oriented commercial delivery layer:

- Multi-product manager with API product slugs
- Stable and beta release channels
- Versioned WordPress plugin releases
- Media Library ZIP package assignment
- Minimum WordPress and PHP requirements
- License-gated update metadata
- One-hour signed release download links
- Download audit events
- Products & Releases operations dashboard
- Product and release list columns

## Setup

1. Open **Party Desk Pro → Products & Releases**.
2. Confirm the seeded **Party Desk Pro** product exists with slug `party-desk-pro`.
3. Upload a plugin ZIP to WordPress Media Library.
4. Create a release, select the product, enter its version, channel, attachment ID, and requirements.
5. Publish the release and mark it available to licensed clients.
6. Send an `update-check` request from an active licensed website.

The legacy single ZIP/version settings remain stored for backward compatibility, but Phase 5 update checks use published product releases.
