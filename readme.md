# Marthrown Enquiry Hub

A WordPress plugin that gives the Marthrown management team a single place to
manage **WP Booking System** bookings and event **enquiries**. FluentCRM Pro is
the source of record for enquiries.

## What it does

- **Bookings** — recreates the WP Booking System "Booking Manager" list view,
  reading bookings live via WPBS's own API (native Pending/Accepted/Trash
  statuses). Booking *management* (calendar, editing) stays inside WP Booking
  System.
- **Event enquiries** — read from **FluentCRM**, which is the source of record.
  A website form (e.g. Kadence Forms) adds the contact to the **Event Enquiries**
  list and applies the **Event Enquiry** tag; the hub lists those contacts. The
  plugin does not hook the form itself and no longer polls email.
- **Respond & manage** — the team triages enquiries through a workflow status
  (new → replied → quoted → converted / closed) stored on the FluentCRM contact,
  and reviews bookings alongside them.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- **FluentCRM Pro** (Fluent Campaign Pro) installed and active — the plugin
  disables itself with an admin notice if this dependency is missing.

## Architecture

PHP is a pure REST API layer; the UI is a React app built with
`@wordpress/scripts` (using WordPress core's bundled `wp-element`,
`@wordpress/components`, and `@wordpress/api-fetch` — no separate React ship).

- **Enquiries** live in FluentCRM. The hub reads contacts in the configured
  Event Enquiries list / Event Enquiry tag and updates a workflow status on them.
  Nothing is written into FluentCRM by this plugin except that status.
- **Bookings** are NOT copied into FluentCRM. They are read live via WPBS's
  `wpbs_get_bookings()` API and presented in a list view that mirrors the WP
  Booking System Booking Manager.

### REST API (`marthrown-enquiry-hub/v1`)

| Method + route                        | Purpose                                        |
| ------------------------------------- | ---------------------------------------------- |
| `GET /enquiries`                      | FluentCRM enquiries, filterable by status/date/search, with counts |
| `POST /enquiries/{id}/status`         | Set workflow status (new/replied/quoted/converted/closed) |
| `GET /bookings?status=…&s=&from=&to=&hide_past=` | Booking list, paginated, with status counts |
| `GET /bookings/calendar?month=YYYY-MM` | Site-wide month overview (cached): bookings + placeholders |
| `GET /calendars`                      | Calendar list for the pickers |

CSV export is a separate nonce-protected `admin-post.php` action
(`meh_export_bookings`) that streams the file, honouring the current filters.

Auth is via the WP REST nonce (`X-WP-Nonce`), same-origin. `permission_callback`
allows the `administrator`, `manager`, and `operations` roles (see `Auth`).

### Bookings Manager

The bookings view recreates the WP Booking System "Booking Manager" list view.
Bookings are read live via WPBS's own `wpbs_get_bookings()` API — never copied
into FluentCRM — and use WPBS's native statuses:

- Period tabs: **All bookings / Current / Upcoming / Past** with live counts.
  Current = today falls within start–end; Upcoming = starts after today;
  Past = ended before today.
- Status tabs: **All / Pending / Accepted / Trash** with live counts.
- Filters: free-text search, start/end date range, and "hide past bookings".
  Period, status and filters combine, and the CSV export honours all of them.
- Columns: ID, Calendar, Guest, Start date, End date, Stay length, Status, and a
  **View** link that opens the booking in WP Booking System.

- **Export CSV** streams the currently-filtered bookings.
- **Add booking** — pick a calendar and open WP Booking System's native
  add-booking screen.
- **Convert to…** — bookings on the configured *Event Enquiry* calendar (where
  website enquiries land) get a Convert control. Choosing a target calendar
  (e.g. Top Site / Full Site) **programmatically creates a real booking** on it
  (`wpbs_insert_booking`), copying the enquiry's dates and form fields, and
  blocks those dates via the target calendar's "booked" legend
  (`wpbs_insert_event`). New bookings are created as `accepted`; both bookings
  are cross-referenced (`meh_converted_from` / `meh_converted_to`). WPBS side
  effects (emails, payments, pricing, inventory) are intentionally not
  triggered.

Per-booking editing happens in WP Booking System (the **View** link); **Add
booking** opens its native add-booking screen. A site-wide **Calendar** overview
is on the side nav, with booking bars coloured by each calendar's WPBS legend
colour, and **placeholders** (manual, non-booking day markers) drawn as dashed
striped bars labelled with their legend item.

The top panel is **Event enquiries**, read from FluentCRM (see Enquiry intake).

### Settings

The settings screen lives at **wp-admin → Settings → Enquiry Hub**
(administrators only). The hub's side nav shows a **Settings** link for
administrators, plus a link across to **WP Booking System**.

**Access** section — tick which roles may open `/bookings` and use the REST API.
Roles are read from WordPress, so anything added by a user-role-editor plugin
appears automatically. Administrators are always allowed (and their checkbox is
disabled) so access can't be locked out; untick other roles to disable them for
testing.

### Bookings settings

On **Settings → Enquiry Hub → Bookings**:

- **Guest name field** / **Guest email field** — the booking-form field label
  (or field ID) to read the guest name/email from (falls back to heuristics).
- **Event Enquiry calendar** — identifies which calendar holds website
  enquiries, enabling the Convert action on its bookings.

### Calendar overview

The side nav has a **Calendar** view: a site-wide month grid across all
calendars, showing pending/accepted bookings as bars. To keep it fast on large
datasets it:

- loads **lazily** — only when the Calendar tab is opened, so it never blocks
  the Overview;
- fetches **one month at a time** via `GET /bookings/calendar`;
- is **cached server-side** for a few minutes (filter `meh_calendar_cache_ttl`,
  invalidated when a conversion writes into a month);
- has **no background polling** (manual Refresh + month navigation instead).

**Placeholders** — manual day markers (WPBS *events* with `booking_id = 0` and a
non-default legend item, e.g. blocked days set by hand) are fetched per calendar,
grouped into consecutive-day spans, and drawn as dashed striped bars so they're
clearly distinct from real bookings.

Layout is a side nav: **Overview** (New enquiries + Bookings Manager) and
**Calendar**.

### File structure

```
marthrown-enquiry-hub/
├── marthrown-enquiry-hub.php        # bootstrap, dependency check, activation
├── includes/
│   ├── class-auth.php               # shared role/permission checks
│   ├── class-source-wpbs.php        # WPBS list-view reader + shared helpers
│   ├── class-calendar-reader.php    # month overview + placeholders (cached)
│   ├── class-settings.php           # settings screen (enquiries/bookings/access)
│   ├── class-rest-enquiries.php     # REST: enquiries (FluentCRM list + tag)
│   ├── class-rest-bookings.php      # REST: bookings (WPBS list view)
│   ├── class-admin-page.php         # menu (links to /bookings) + app enqueue
│   ├── class-wpbs-banner.php        # back-link bar on WP Booking System pages
│   └── class-frontend-bookings.php  # /bookings route hosting the React app
├── src/                             # React source (built by wp-scripts)
│   ├── index.js  index.scss  api.js  App.js
│   ├── hooks/usePolling.js
│   └── components/         # EnquiryManager, BookingsManager, …
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

## Testing

PHP tests use PHPUnit with [Eris](https://github.com/giorgiosironi/eris) for
property-based testing; JS tests use `wp-scripts test-unit-js` (Jest). Nothing
test-related deploys — `vendor/`, `tests/`, `phpunit.xml`, `composer.json` and
`.wp-env.json` are all in `.lftp_ignore`.

### Two suites

`phpunit.xml` declares two suites, and the split matters:

| Suite | Needs | Covers |
| --- | --- | --- |
| `pure` | PHP + Composer only | Anything touching no WordPress function and no database: `Validator`, `EnquiryQuery`, `FieldMapper`, `Clock` |
| `wordpress` | a WordPress checkout **and** a live MySQL/MariaDB | `Schema`, `EnquiryStore`, `HistoryRecorder`, `Lifecycle`, notes, intake, CRM linkage, REST routes |

Run them with `npm run test:php:pure`, `npm run test:php:wp`, or `npm run
test:php` for both.

### Prerequisites

**PHP 8.2** with `mbstring`, `openssl`, `curl`, `zip`, `mysqli` and `pdo_mysql`.
On Windows, `winget install --id PHP.PHP.8.2 --scope user` works without
administrator rights. One gotcha: the shipped `php.ini` leaves `extension_dir`
commented out, so PHP falls back to its compiled-in default of `C:\php\ext` and
**every** extension fails to load. Set it to an absolute path:

```ini
extension_dir = "C:/Users/<you>/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.2_Microsoft.Winget.Source_8wekyb3d8bbwe/ext"
```

`mbstring` is not optional — the validator's truncation and the history
recorder both rely on `mb_strlen`/`mb_substr`.

**Composer**, then install the dev dependencies:

```
composer install
npm install
```

### Local route (no Docker)

Preferred where Docker Desktop is unavailable, needs no administrator rights and
no WSL. Three pieces: a WordPress checkout, a database, and two environment
variables.

**1. WordPress test library** — a shallow clone is enough:

```
git clone --depth 1 --branch trunk https://github.com/WordPress/wordpress-develop.git C:\Users\<you>\dev\wordpress-develop
```

Create `wordpress-develop/wp-tests-config.php` (copy
`wp-tests-config-sample.php` and edit). It must define `ABSPATH` pointing at the
checkout's `src/` directory, plus the database constants below. Values there are
test-only — that database holds nothing real, so the salts can be any fixed
strings.

**2. MariaDB from the ZIP archive** — no installer, no service, no admin.
Download `mariadb-<version>-winx64.zip`, verify its published SHA-256, unzip it,
then initialise a data directory:

```
mysql_install_db.exe --datadir=C:\Users\<you>\dev\mariadb-data
```

Create the test database and a non-root user for it:

```sql
CREATE DATABASE meh_tests DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'meh_test'@'127.0.0.1' IDENTIFIED BY '<password>';
GRANT ALL PRIVILEGES ON meh_tests.* TO 'meh_test'@'127.0.0.1';
GRANT ALL PRIVILEGES ON `meh\_tests\_%`.* TO 'meh_test'@'127.0.0.1';
```

That second grant is what lets several PHPUnit processes run at once — see
[One database per PHPUnit process](#one-database-per-phpunit-process).

The server binds to `127.0.0.1` only and runs on port 3307, keeping it off the
default port and unreachable from the network. It is a disposable test database:
the WordPress test bootstrap drops and recreates its tables on every run, so
never point these settings at anything you care about.

**3. Environment variables** (user scope, no admin needed). All three have
defaults, so a shell that never set them still works if your layout matches the
one above:

| Variable | Value | Default when unset |
| --- | --- | --- |
| `WP_TESTS_DIR` | `…\wordpress-develop\tests\phpunit` | `%USERPROFILE%\dev\wordpress-develop\tests\phpunit` |
| `MEH_DB_DATA` | `…\mariadb-data` | `%USERPROFILE%\dev\mariadb-data` |
| `MEH_DB_PORT` | `3307` | `3307` |

Add the MariaDB `bin` directory to your PATH so `npm run db:start` resolves
`mysqld`. `tests/bootstrap.php` reads `WP_TESTS_DIR` (falling back to
`WP_PHPUNIT__DIR`, `/wordpress-phpunit`, `/tmp/wordpress-tests-lib` and the
default above), so no code change is needed to switch between routes.
`npm run db:start` and `npm run db:stop` go through `tests/db.js`, which fills in
`MEH_DB_DATA` and `MEH_DB_PORT` the same way.

Then, in two terminals:

```
npm run db:start      # leave running; Ctrl-C or `npm run db:stop` to halt
npm run test:php
```

The WordPress bootstrap shells out to a `php` binary of its own, so **PHP must
be on your PATH**, not just reachable by absolute path — an easy failure to
misread, because it surfaces as `'php' is not recognized`.

### Docker route

`.wp-env.json` is configured, so if you have Docker Desktop and WSL2:

```
npm run env:start
npm run test:php:wp-env
```

The per-run database applies here too, and the container's database user can
create one. If a container ever refuses, run PHPUnit with `MEH_TESTS_DB_NAME` set
to wp-env's own test database to fall back to sharing it.

This needs administrator rights to install WSL2 and Docker Desktop, and on a
corporate machine Docker Desktop may require a paid licence. The local route
above exists to avoid that chain entirely.

### One database per PHPUnit process

The WordPress test library reinstalls WordPress on every invocation, and
installing starts by dropping the core tables. Two PHPUnit processes pointed at
one database therefore sabotage each other: the second process's install removes
`wptests_options` from under the first, which surfaces as
`Table 'meh_tests.wptests_options' doesn't exist` part-way through a run, or as a
deadlock, in tests that pass perfectly well on their own.

So each process gets its own database, `meh_tests_<pid>`, created empty before
the install and dropped when the process exits. Nothing else changes: the table
prefix stays `wptests_`, so the tests that take `$wpdb->prefix` and add a segment
of their own are untouched, and so are the ones that swap in a fixed prefix like
`mehfacts_` — two runs can use the same table names because they are in different
databases.

Two knobs, both rarely needed:

| Variable | Effect |
| --- | --- |
| `MEH_TESTS_DB_NAME` | Use this database as-is. Neither created nor dropped. `MEH_TESTS_DB_NAME=meh_tests` restores the old single shared database, and with it the collisions above. |
| `MEH_TESTS_RUN_ID` | Use this instead of the process id in the database name, for a CI matrix that would rather name its own. |

The wiring is `tests/wp-tests-config.php`. `DB_NAME` is a constant, read both here
and in the separate PHP process the library shells out to for the install, so it
cannot be changed after the fact — that file defines it first, then defers to your
own `wp-tests-config.php` for the credentials, `ABSPATH`, salts and prefix. Your
config file needs no edit; `tests/bootstrap.php` passes its location along.

If a run is killed outright, its database survives. They are harmless and named
distinctly, but to sweep them up:

```sql
SHOW DATABASES LIKE 'meh\_tests\_%';
```

### Property-based testing

The design defines 43 correctness properties, each with its own test task in the
implementation plan. `tests/Generators.php` holds the shared Eris generators —
valid enquiries, Candidate Date Range lists of one to three ranges, term sets of 0 to 20, values at
exactly their stored capacity, `total_guests` boundaries of 1 and 10000, and an
adversarial string set (`'`, `"`, `\`, `--`, `;`, `%`, `_`, `%s`, `%d`) used to
prove every submitted value is bound rather than interpolated into SQL.

`tests/fakes/` holds in-memory stand-ins for FluentCRM (`FakeCrm`) and WP
Booking System (`FakeWpbs`), so the CRM and booking paths are testable with
neither plugin installed. Both register their global function shims only when the
real function is absent, so a test run inside a site that has them active still
exercises the real API.

**Eris API version.** Eris 0.14 exposes generators as static methods on
`Eris\Generators` — `\Eris\Generators::choose( 1, 10 )`. Other versions expose
namespaced *functions* instead, as `Eris\Generator\choose( 1, 10 )`. The two are
not interchangeable and picking the wrong one breaks every call site at once, so
`tests/pure/HarnessTest.php` pins it deliberately: if an Eris upgrade changes the
API, that one test fails rather than every property test failing obscurely.

Note also that `tests/Generators.php` declares a class called `Generators` of its
own, so it cannot `use Eris\Generators` — the names collide. It refers to Eris
by fully-qualified name for that reason.

**Verifying WordPress-independence.** `npm run test:php` runs both suites in one
PHPUnit process, and because the `wordpress` suite needs WordPress, WordPress
ends up loaded for the whole run. Only `npm run test:php:pure` genuinely proves
the pure code needs no WordPress — `tests/bootstrap.php` skips the WordPress boot
entirely when that suite is named. Run the pure suite on its own before trusting
that property.

## Enquiry intake

The plugin does **not** capture enquiries itself — the website form does, and the
hub reads the result. Point your Kadence Form (or Fluent Forms) at FluentCRM so a
submission:

1. creates/updates the contact, and
2. adds it to the **Event Enquiries** list and applies the **Event Enquiry** tag.

The hub then lists those contacts. Configure which list/tag to read on
**Settings → Enquiry Hub → Enquiries**; if nothing is saved, the plugin
auto-detects them by those names. When both a list and a tag are set, a contact
must match both to appear.

There is **no cron and no email polling** — enquiries are read live from
FluentCRM and bookings live from WP Booking System. Legacy cron events from the
email-polling era are cleared on activation/deactivation.

### Intake secret transport

The intake endpoint accepts the Intake Secret two ways: the
`X-MEH-Intake-Secret` request header (preferred) or a `meh_secret` query
parameter on the destination URL (fallback). **The header transport is the one in
use.**

The Kadence Blocks Pro version installed on the live site cannot send a custom
request header — its webhook Submit Action offers a destination URL and
form-field-to-webhook mappings and nothing else — so the plugin attaches the
header itself. Because the form and the plugin are on the same site, the webhook
POST is a loopback request WordPress makes while handling the submission, and
`IntakeEndpoint::attach_secret_header()` adds the header to it through
`http_request_args`. The filter is registered in `IntakeEndpoint::init()` on
`plugins_loaded`, well before form processing, so it is in place by the time
Kadence calls `wp_remote_post()`.

`http_request_args` fires for **every** outbound request WordPress makes, so the
filter is scoped tightly: it attaches nothing unless the request is going to this
site's own host and port *and* resolves to the intake route itself (compared
against `IntakeEndpoint::url()`, handling both the pretty-permalink path and the
`rest_route` query parameter). It attaches nothing when no secret is stored, and
it never overwrites an `X-MEH-Intake-Secret` header the request already carries.

The `meh_secret` query parameter remains supported as the fallback for a sender
that is not on this site or that WordPress does not make the request for. It is
the **weaker** transport: a secret in the request URL is written into server
access logs, where a header is not — which is why the Settings screen states that
next to the Intake Secret control (Requirement 16.15).

**What an administrator configures on the form.** The Kadence webhook Submit
Action needs two things and no secret:

1. **Destination URL** — the intake endpoint, shown on **Settings → Enquiry Hub →
   Intake** (typically `https://<site>/wp-json/marthrown-enquiry-hub/v1/intake`).
   Leave the secret off the URL; the plugin supplies it in the header.
2. **Field mappings** — one entry per form field. Payload field names are matched
   to enquiry fields by label, ignoring case and separators, so a field labelled
   "First Name" supplies `first_name` with nothing configured. Where a name does
   not match, set the payload field explicitly in the Intake section's nine
   mapping controls. Add one field holding the form's own identifier and name it
   in **Form identifier field** so the enquiry's `source` records which form sent
   it; without it the enquiry is recorded as `webhook:unidentified`.

The Intake Secret itself is set once in Settings and never appears on the form.

### Enquiry workflow status

Status is stored per contact via `SubscriberMeta`
(`object_type = 'custom_field'`, key `meh_enquiry_status`), so it never clashes
with FluentCRM's own subscribed/unsubscribed status:

`new` → `replied` → `quoted` → `converted` / `closed`

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

The hub is available on the front end at `https://<site>/bookings`, without
creating a WordPress page. A rewrite rule serves the React app in a lightweight
shell that calls `wp_head()`/`wp_footer()`, so it **inherits the active theme's
stylesheet** and shows the **site's custom logo** (falling back to the site name)
in the header.

It is kept out of search engines three ways: a `noindex,nofollow` meta tag, an
`X-Robots-Tag: noindex, nofollow` response header, and a `Disallow: /bookings/`
line added to `robots.txt`. It also requires login, so crawlers only ever see the
login redirect.

Access is restricted:

- Not logged in → redirected to the login page and back to `/bookings` after
  login. The login URL honours the site's custom login slug via the
  `MEH_LOGIN_SLUG` constant (default `admin-console`); set it to `''` to fall
  back to `wp_login_url()`, or override the whole URL with the `meh_login_url`
  filter.
- Logged in without permission → `403`.
- Allowed roles: `administrator`, `manager`, `operations` (administrators always
  pass). Adjust with the `meh_allowed_roles` filter:

```php
add_filter( 'meh_allowed_roles', function ( $roles ) {
    $roles[] = 'events_team';
    return $roles;
} );
```

The rewrite rule is registered on every request, but a registered rule only
answers a URL once a flush has written it into the `rewrite_rules` option. That
flush used to happen on activation alone — and deployment here is an SFTP file
mirror that never re-activates the plugin, so `/bookings` 404ed on any
environment the files were mirrored into, or where anything else flushed the
rules while this plugin was inactive. Re-saving permalinks was the manual repair.

It is no longer needed. `FrontendBookings::maybe_flush()` runs on `wp_loaded` and
flushes once per deployed version, recording the version in the
`meh_rewrite_version` option so a working route costs nothing on later requests.
`wp_loaded` rather than `init` because a flush stores the whole rule set and can
only store what has been registered when it runs: flushing part-way through
`init` would drop every rewrite, post type and taxonomy registered after this
plugin and 404 other people's URLs instead.
If the rule goes missing after that, it is flushed for once more and then left
alone, because a rule something else filters away for good must not cost a flush
on every request. Sites on plain permalinks are skipped entirely — they store no
rewrite rules at all, and the query var still works: `/?meh_bookings=1`.

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
