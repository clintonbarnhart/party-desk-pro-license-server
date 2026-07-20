# Phase 6 — Direct Release Manager

Version: `3.6.0-alpha6`

Phase 6 removes the need to look up WordPress Media Library attachment IDs. Administrators can upload an installable WordPress plugin ZIP directly from the release editor.

## Included

- Direct ZIP upload from the New Release screen
- ZIP archive validation
- WordPress plugin header detection
- Automatic plugin name and version detection
- Manual version override
- Stable and beta channels
- Duplicate product/version/channel protection
- Package SHA-256 integrity metadata
- Secure licensed downloads
- Per-release download counters
- Improved release history columns and delivery dashboard

## Safety

The server must have the PHP Zip extension enabled. Packages are limited to 100 MB and must contain a valid WordPress plugin header. Test releases on staging before production distribution.
