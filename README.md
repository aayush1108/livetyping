# Live Typing Indicator (livetyping)

**Author:** Aayush  |  **Version:** 1.1.0  |  **License:** GPL-3.0-or-later
**Requires:** GLPI 11.0.6 or newer, PHP 8.2+

Shows "**Maria Lopez is typing…**" at the bottom of a ticket while a colleague is writing a reply, so two people
do not answer the same ticket at the same time.

## Install

1. Copy the `livetyping` folder into GLPI's `plugins/` directory
   (final path: `<glpi>/plugins/livetyping/setup.php`).
2. In GLPI go to **Setup > Plugins**, click **Install**, then **Enable**.
3. Optional: **Setup > Plugins > Live Typing Indicator** (gear icon) to choose the refresh interval and which
   profiles can use it. Everything is on for all profiles by default.

## How it works

- The indicator is added **only on ticket pages** (`/front/ticket.form.php?id=...`) and only for allowed profiles.
- While you type in a reply, task or solution box, your browser tells the server "typing" at most once every
  3 seconds, and "stopped" 4 seconds after your last key (or when you send, or leave the page).
- Every open ticket checks who else is typing once per refresh interval (default 5 s, admin choice 3-30 s)
  and only while the browser tab is visible. Someone counts as typing for 10 seconds after their last signal.
- It is "live" within a few seconds (polling), not instant.

## Privacy and security design

- **Who sees whom:** a person only learns who is typing on tickets they are allowed to view (GLPI's own
  `canViewItem()` rules). Names come from GLPI's own `getUserName()`, so its anonymisation setting for the
  simplified interface is respected. Only names are sent, never user ids. Nothing is stored beyond a
  short-lived row per (ticket, user), which is deleted when typing stops and cleaned up automatically.
- **Access control:** login required; the plugin must be enabled for the user's active profile; the admin page
  needs the GLPI `config` update right (checked first). Refusals are generic and never reveal whether a ticket
  exists.
- **CSRF:** GLPI enforces its token on every POST (the script sends GLPI's `X-Glpi-Csrf-Token` header).
- **Cross-site scripting:** the browser script never builds HTML. The server text is shown with `textContent`
  only (no `innerHTML`/`eval`), names are cleaned (single line, 60 characters, no control characters) and sent
  as JSON. The admin page escapes everything and contains no JavaScript and no inline styles. The script and
  stylesheet are plain files served by GLPI, so a strict Content-Security-Policy works.
- **Denial of service:** a per-session rate limit (10 requests / 5 s, shared by all tabs) checked before any
  database access; every query is bounded and indexed (at most 5 rows read per poll, rows per user bounded); the
  browser polls only while the tab is visible, with random jitter, sends at most one "typing" signal per 3 s and
  backs off exponentially on errors. It pauses after repeated failures and resumes by itself (tab shown, network
  back, page restored); it stops for good only on "forbidden" or when the login session is gone. Inputs are strictly
  validated; log lines are capped and sanitised.
- **Independence:** the plugin only creates, reads and drops its own three tables
  (`glpi_plugin_livetyping_states`, `_settings`, `_profiles`) and never changes GLPI core data. Disabling or
  uninstalling it restores stock behaviour; the browser script is simply no longer loaded.
- **Code hygiene:** `declare(strict_types=1)` everywhere, direct file access refused, GLPI's query builder for all
  database access, no dynamic code execution, no file access, no outbound network calls, no dependencies.

## Server load

About one small request per **open ticket tab per refresh interval** (tabs in the background send nothing).
For example 100 visible ticket tabs at 5 s is about 20 small requests per second. Raise the interval on the
admin page if your server is small.

## Upgrading from 1.0.0

Copy the new files over the old ones, then in **Setup > Plugins** click **Install/Update** on the plugin. This only
adds one database index. See `CHANGELOG.md`. Security policy and threat model: `SECURITY.md`. Translations: copy
`locales/livetyping.pot` to `locales/<language>.po` and compile it.

## Notes

- Typing is detected in editors named `content` (ticket reply, task and solution boxes, plain or TinyMCE).
- Works for the standard and the simplified interface (subject to the profile setting).
- The files `public/js/typing.js` and `public/css/typing.css` are cached by browsers; the plugin version is
  added to their URL, so an upgrade is picked up automatically.
