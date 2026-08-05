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

## Architecture

PHP is a pure REST API layer; the UI is a React app built with
`@wordpress/scripts` (using WordPress core's bundled `wp-element`,
`@wordpress/components`, and `@wordpress/api-fetch` — no separate React ship).

- **Enquiries** come from web forms, email (Graph), and WPBS submissions, and
  are logged into FluentCRM (source of record) via a shared writer, tagged by
  source. The React app reads/updates them through the REST API.
- **Bookings** are NOT copied into FluentCRM. They are read live from the
  `wpbs_` tables and bucketed by date math plus an acknowledgment table this
  plugin owns.

### REST API (`marthrown-enquiry-hub/v1`)

| Method + route                        | Purpose                                        |
| ------------------------------------- | ---------------------------------------------- |
| `GET /enquiries`                      | Paginated, filterable by source/status/date    |
| `POST /enquiries/{id}/status`         | Set enquiry status (new/replied/resolved)      |
| `GET /bookings?bucket=…`              | `new`/`upcoming`/`current`/`past`, paginated   |
| `POST /bookings/{id}/acknowledge`     | Move a booking out of "new"                     |

Auth is via the WP REST nonce (`X-WP-Nonce`), same-origin. `permission_callback`
allows the `administrator`, `manager`, and `operations` roles (see `Auth`).

### Booking buckets

- **new** — no row in `wp_marthrown_booking_ack` (any dates). The only
  manually-driven bucket; sorted by check-in ascending so the most urgent are on
  top. Nothing ages out silently — a missed check-in stays here, flagged, until
  acknowledged.
- **upcoming** — acknowledged, check-in in the future.
- **current** — acknowledged, today between check-in and check-out.
- **past** — acknowledged, check-out within the last 30 days.

### File structure

```
marthrown-enquiry-hub/
├── marthrown-enquiry-hub.php        # bootstrap, dependency check, activation
├── includes/
│   ├── class-auth.php               # shared role/permission checks
│   ├── class-fluentcrm-writer.php   # shared write path (SubscriberMeta + tag)
│   ├── class-source-wpbs.php        # WPBS read layer + acknowledgment table
│   ├── class-source-email.php       # Graph API poll -> FluentCRM
│   ├── class-source-webform.php     # Kadence/Fluent Forms -> FluentCRM
│   ├── class-cron.php               # WP-Cron (email poll)
│   ├── class-settings.php           # Graph credentials settings screen
│   ├── class-rest-enquiries.php     # REST: enquiries
│   ├── class-rest-bookings.php      # REST: bookings + acknowledge
│   ├── class-admin-page.php         # mounts #enquiry-hub-root, enqueues build/
│   └── class-frontend-bookings.php  # /bookings route hosting the React app
├── src/                             # React source (built by wp-scripts)
│   ├── index.js  index.scss  api.js  App.js
│   ├── hooks/usePolling.js
│   └── components/…
├── build/                           # compiled bundle (CI only, git-ignored)
├── assets/                          # frontend.css, admin.css (banner)
├── package.json
└── readme.md
```

## Build

```
npm install
npm run build      # outputs build/index.js + build/index.asset.php (+ css)
npm start          # watch mode for development
```

CI runs `npm install && npm run build` before the SFTP deploy, and only the
compiled `build/` ships (see `.lftp_ignore`). Commit a `package-lock.json` to
switch CI to `npm ci` with dependency caching.

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

One WP-Cron event runs every 15 minutes:

- `meh_cron_email_poll` — polls the Graph mailbox folder for unread messages.

Bookings are read live from WPBS on request, so there is no booking cron. The
event is registered on activation and cleared on deactivation.

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

Deployment is handled by GitHub Actions (see `.github/workflows`) over **SFTP**
using `pressidium/lftp-mirror-action` (lftp mirror), the same approach proven on
the IONOS-hosted sibling projects. It works on managed hosts with SFTP-only
access and no shell.

- Pushes to the **`develop`** branch deploy to the **`marthrown-enquiry-hub-staging`**
  folder on the WordPress site.
- Merging to **`main`** (manual action only) deploys to the live
  **`marthrown-enquiry-hub`** folder for pre-release testing.

Repo/dev files are skipped via `.lftp_ignore`. Checkout uses `fetch-depth: 0`
so `restoreMTime` can set file times from git history and avoid re-uploading
everything each run.

### Required repository secrets

| Secret            | Purpose                                                       |
| ----------------- | ------------------------------------------------------------- |
| `SFTP_HOST`       | SFTP host                                                     |
| `SFTP_PORT`       | SFTP port (e.g. 22)                                           |
| `SFTP_USER`       | SFTP username                                                 |
| `SFTP_PASS`       | SFTP password                                                 |
| `SFTP_REMOTE_DIR` | Path to the site's `wp-content/plugins` dir (no trailing `/`) |

The workflows append the plugin folder to `SFTP_REMOTE_DIR`
(`…/marthrown-enquiry-hub-staging` for develop, `…/marthrown-enquiry-hub` for
main), so a single path secret covers both targets.

The mirror is additive/update-only (no `--delete`), matching the sibling
projects, so removing a file from the repo won't delete it on the server. Add
`options: --delete` to a workflow if you want an exact mirror.

## Front-end bookings page (`/bookings`)

The hub is also available on the front end at `https://<site>/bookings`, without
creating a WordPress page. A rewrite rule serves a self-contained, `noindex`
page that reuses the dashboard's table.

Access is restricted:

- Not logged in → redirected to the login page and back to `/bookings` after
  login. The login URL honours the site's custom login slug via the
  `MEH_LOGIN_SLUG` constant (default `admin-console`); set it to `''` to fall
  back to `wp_login_url()`, or override the whole URL with the `meh_login_url`
  filter.
- Logged in without permission → `403`.
- Allowed roles: `administrator`, `manager`, `operations` (administrators always
  pass). Adjust with the `meh_bookings_allowed_roles` filter:

```php
add_filter( 'meh_bookings_allowed_roles', function ( $roles ) {
    $roles[] = 'events_team';
    return $roles;
} );
```

The rewrite rule is registered and flushed on activation. If `/bookings` returns
a 404 after an update, re-save permalinks (Settings → Permalinks) to flush rules.

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
