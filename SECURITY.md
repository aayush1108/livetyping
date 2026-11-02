# Security

## Reporting a problem

Please do not open a public issue for a security problem. Contact the author (Aayush) privately with the plugin
version, your GLPI and PHP versions and the steps to reproduce. You will get an answer as soon as possible.

## What the plugin does and does not do

- Adds one script and one stylesheet, only on ticket pages, only for profiles you allow.
- Exposes one JSON endpoint (`ajax/typing.php`) and one admin page (`front/config.php`).
- Stores one short-lived row per (ticket, user) while someone types, plus its own settings and profile list.
- It never reads or changes GLPI core data other than checking whether the current user may view a ticket
  (`Ticket::canViewItem()`), and it makes no network calls to the outside.

## Threat model and mitigations

| Threat | Mitigation |
|---|---|
| Someone not allowed learns who is typing on a ticket | The endpoint requires a login, the plugin enabled for the active profile, and `canViewItem()` on that ticket. Refusals are generic and identical for "no access" and "does not exist". |
| Cross-site scripting through a name | Names go through `getUserName()` (honours anonymisation), are cleaned (single line, 60 characters), sent as JSON and shown with `textContent` only. The script has no `innerHTML`/`eval`. The admin page escapes all output and has no JavaScript and no inline styles, so a strict Content-Security-Policy works. |
| Cross-site request forgery | GLPI's CSRF check on every POST (header `X-Glpi-Csrf-Token`), POST used for every change. |
| Forged or malformed input | Strict whitelists and integer parsing (`^[0-9]{1,9}$`, no trailing newline), unknown values rejected, method whitelist. |
| Denial of service | Rate limit per session checked before any database access; every query bounded and indexed (at most 5 rows read per poll, rows per user bounded); the browser polls only while visible, with jitter and exponential back-off; capped, sanitised log lines. |
| Data leak through errors | Generic error codes; details only in `files/_log/livetyping.log` (control characters removed, length capped). |
| Removing the plugin breaks GLPI | Only its own three tables; uninstall drops them; no core data is touched. |
| Plugin error blocks GLPI | The endpoint is isolated; `plugin_init` never throws and does no database access on non-ticket pages. |

## Known limits

- The indicator is visible to every allowed profile that can view the ticket. Use the profile setting to restrict it.
- Presence is approximate (polling): it can lag by up to one refresh interval.
