Party Desk Pro License Server
Version 2.8.2

Professional license settings redesign with:
- Clear server health and setup status
- Live license-key format preview
- Improved plugin ZIP selection and delivery status
- License API connection test
- Copy buttons for server and API URLs
- Organized email and customer portal settings
- Responsive, modern WordPress admin interface

This build preserves the existing signup form, Square payment links, license API, customer accounts, portal preview, and license delivery features.


= 2.2.0 =
* Keeps My Account terminology throughout the customer-facing portal settings.
* Redesigns Square Payments with professional connection, checkout, email, status, and help cards.
* Preserves the stored Square access token when saving other settings with the token field blank.

= 2.3.1 =
* Fixed squeezed My Account live preview.
* Added working Desktop and Mobile preview switching.
* Added responsive preview scaling and correct preview height calculation.


= 2.3.2 =
* Fixed the Signup Form Builder live preview so desktop cards and fields no longer collapse.
* Added working Desktop and Mobile preview controls to the Signup Form Builder.
* Added automatic scaled-height and resize handling for both admin builders.


= 2.3.3 =
* Rebuilt My Account subscription, license status, and website allowance cards for better readability.
* Prevented card labels, values, prices, and renewal details from being clipped or squeezed.
* Added a cleaner stacked card layout for narrow and mobile displays.


= 2.4.0 =
* Converted the customer signup form into a true two-step experience.
* Step 1 lets customers select and confirm a subscription plan.
* Step 2 collects business and contact information with Back and Continue navigation.
* Added a progress indicator, selected-plan summary, client-side validation, and responsive mobile behavior.


= 2.4.2 =
* Rebuilt the My Account admin mobile preview to force the same responsive stacking used by the customer-facing page.
* Fixed clipped headings, squeezed summary cards, overlapping account/download panels, and incorrect mobile top-bar layout in preview mode.


= 2.8.2 =
* Redesigned the Edit Plan screen with a professional card-based layout.
* Added Every 6 Months as a billing period.
* Updated plan cards, signup cards, license pricing, and license expiration logic for six-month plans.
* Added a more polished live signup-card preview in the plan editor.

= 3.5.0-alpha5 =
* Added products and releases manager.
* Added stable and beta update channels.
* Added signed, license-gated release downloads.
* Added product delivery analytics dashboard.

= 3.6.0-alpha6 =
* Replaced manual attachment IDs with direct plugin ZIP uploads.
* Added ZIP validation and automatic plugin name/version detection.
* Added duplicate release protection, package SHA-256 tracking, and download counts.
* Improved the Products & Releases dashboard and release history columns.

= 3.7.0-alpha7 =
* Added commercial setup wizard and readiness checks.
* Added public product manifest endpoint.
* Added richer secure update metadata and update delivery controls.
* Added client update integration documentation inside WordPress.


## Phase 8 Automated Signup Checkout

Version `3.8.0-alpha8` connects `[pdpsignup]` to Square-hosted recurring checkout and automatically prepares customer accounts, subscriptions, and licenses after webhook confirmation. See `PHASE8.md`.
