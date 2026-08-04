# Marthrown Enquiry Hub

A WordPress plugin that gives the Marthrown management team a single place to
manage **WP Booking System** bookings and event **enquiries**. FluentCRM Pro is
the source of record for enquiries.

## What it does

- **Bookings** — reads the native WP Booking System (`wpbs_`) tables and shows
  current, upcoming and past bookings on one dashboard. Booking *management*
  stays inside the WP Booking System plugin for now.
- **Enquiries** — collects enquiries from three sources and writes them into
  FluentCRM Pro through a single shared write path:
  - Web form submissions (Kadence Forms, Fluent Forms)
  - Email (Microsoft Graph API mailbox poll)
  - WP Booking System bookings (mirrored as activities)
- **Respond & manage** — from the dashboard the team can create an event
  booking from an enquiry, or remove (close/lose) cancelled or fallen-through
  enquiries. All of this reads from and writes to FluentCRM.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- **FluentCRM Pro** (Fluent Campaign Pro) installed and active — the plugin
  disables itself with an admin notice if this dependency is missing.

## File structure

```
marthrown-enquiry-hub/
├── marthrown-enquiry-hub.php     # bootstrap, dependency check, activation hooks
├── includes/
│   ├── class-source-wpbs.php     # reads wpbs_ tables, writes to FluentCRM
│   ├── class-source-email.php    # Graph API poll + write to FluentCRM
│   ├── class-source-webform.php  # hooks Kadence/Fluent Forms submissions
│   ├── class-fluentcrm-writer.php# single shared write path (Activity/Note + tag)
│   ├── class-cron.php            # WP-Cron schedules for WPBS + email pulls
│   └── class-admin-dashboard.php # unified admin page, queries FluentCRM
├── assets/
│   ├── admin.css
│   └── admin.js
└── readme.md
```

## Configuration

Microsoft Graph email polling reads credentials from WordPress options:

| Option                     | Purpose                                    |
| -------------------------- | ------------------------------------------ |
| `meh_graph_tenant_id`      | Entra (Azure AD) tenant ID                 |
| `meh_graph_client_id`      | App registration client ID                 |
| `meh_graph_client_secret`  | App registration client secret (obfuscated)|
| `meh_graph_mailbox`        | Mailbox (UPN/email) to poll                |
| `meh_graph_folder`         | Mail folder to poll (default `inbox`)      |

These are configured on **Enquiry Hub → Settings**. The Graph app needs the
`Mail.ReadWrite` application permission (with admin consent) so unread messages
can be read and then marked as read.

Set these via a settings screen or `update_option()`. Store the client secret
securely — never commit it to the repository.

## Scheduling

Two WP-Cron events run every 15 minutes:

- `meh_cron_wpbs_poll` — polls `wpbs_` tables for new bookings since the last
  run (`meh_wpbs_last_sync`) and logs them into FluentCRM.
- `meh_cron_email_poll` — polls the Graph mailbox folder for unread messages.

Both are registered on activation and cleared on deactivation.

## Tags used

Every enquiry is tagged by source so the dashboard can list and filter it:

| Tag slug         | Meaning                            |
| ---------------- | ---------------------------------- |
| `source-webform` | Enquiry from a web form            |
| `source-email`   | Enquiry from email (Graph)         |
| `source-wpbs`    | Enquiry from WP Booking System     |

The latest enquiry note is stored per subscriber via `SubscriberMeta`
(`object_type = 'custom_field'`, key `meh_latest_enquiry`).

## Deployment

Deployment is handled by GitHub Actions (see `.github/workflows`).

- Pushes to the **`develop`** branch deploy to a **`-staging`** folder on the
  WordPress site.
- Merging to **`main`** (manual action only) deploys to the live plugin folder
  for pre-release testing.

## Staging test records

The staging copy deploys to `marthrown-enquiry-hub-staging/` on the same
WordPress site as production. To keep test data separable, any FluentCRM record
created while running in staging is:

- given a `TEST_` name prefix (configurable via the `MEH_TEST_PREFIX` constant), and
- tagged with `test-record` for one-click bulk removal in FluentCRM before go-live.

Staging is detected in this order: an explicit `MEH_ENVIRONMENT` constant, then
`wp_get_environment_type()`, then the plugin running from a `-staging` folder.
Override the result with the `meh_is_staging` filter if needed:

```php
add_filter( 'meh_is_staging', '__return_true' );
```

The staging GitHub Actions workflow writes a `meh-environment.php` marker at
deploy time that defines `MEH_ENVIRONMENT` as `staging`, so detection is
explicit and does not rely on the folder name. That file is git-ignored and the
production deploy (rsync `--delete`) removes it from the live folder, so
production is never flagged as staging.

The dashboard filter bar has a **Hide test records** toggle that excludes any
`test-record`-tagged contacts. Test rows are highlighted and badged when shown.

## Development status

This is an initial scaffold. Several queries (WPBS schema columns, FluentCRM
tag filtering) are marked as skeletons and should be verified against the live
installation before production use.
