# Typing Indicator for GLPI

Shows a live blue **"Jane Doe typing…"** line directly under the comment box of Tickets,
Problems and Changes while another user is writing.

| GLPI | Supported |
|------|-----------|
| 10.0.x | ✅ |
| 11.x | ✅ |
| 12.x | ✅ Adapted to GLPI 12 (no CSRF tokens: browser Sec-Fetch-Site/Origin check; PHP 8.3) |

## Features

- Detects typing in followups (comments), tasks and solutions — TinyMCE or plain textarea.
- Always one blue line directly under the comment (followup) editor; it can't be stretched by GLPI's layout.
- *"Jane Doe typing…"*, *"Jane Doe & Bob Smith typing…"*, *"Jane Doe, Bob Smith & Li Wei typing…"*, *"Jane Doe, Bob Smith & 3 others typing…"*.
- **Private followups** are only announced to users allowed to see private followups.
- Respects ticket visibility: you only get typing info for items you can open.
- No cron needed — heartbeats expire after 8 seconds (closed tab = indicator disappears).
- Polling slows down when the browser tab is hidden.

## Who can use it (admin only)

**Setup → Plugins → Typing indicator** (wrench icon). Only administrators who can update the
general configuration (Super-Admin) can open this page.

| Setting | Effect |
|---------|--------|
| **Enable the typing indicator** | Global on/off switch. |
| **Can see who is typing** — profiles / groups | The indicator is shown to these users. |
| **Is shown to others when typing** — profiles / groups | These users' typing is announced to others. |

A user is allowed when their **active profile OR one of their groups** is selected. Leaving both
empty allows nobody. On first install only Super-Admin profiles are selected.

Regular users have no settings at all — they only see the indicator when allowed.

## Database impact

| Table | What the plugin does |
|-------|----------------------|
| `glpi_plugin_typingindicator_typings` (own table) | Create / read / write. At most **one row per user**, rows expire after 8 s and are purged after 2 min. |
| `glpi_configs` | Stores its 5 settings under context `plugin:typingindicator` only. |
| `glpi_profiles`, `glpi_groups`, `glpi_profilerights`, `glpi_users` | **Read only.** |
| `glpi_profilerights` | On install, deletes only the obsolete `plugin_typingindicator` right left by v1.0.0, if present. |

No core table is altered, no ticket/followup data is read or stored, and the typed text is
**never sent** to the server — only "user X is typing a followup/task/solution on item Y".
Uninstall drops the plugin table and deletes its settings rows.

## Security

- Login required; the item must be readable by the user (GLPI's own permission check).
- Settings page needs the *update general configuration* right (Super-Admin).
- `itemtype` and `kind` are whitelisted; ids cast to integers; all SQL goes through GLPI's query builder.
- State-changing requests are POST-only and CSRF-protected by GLPI (token on 10/11, browser Sec-Fetch-Site/Origin check on 12).
- A user can only create or delete **their own** typing status.
- Private followups and tasks are announced only to users who can see them; self-service users without task rights never see task typing.
- Inactive/deleted users are never shown; responses expose display names only (no ids, logins, emails).
- XSS: the browser inserts text with `textContent` only; the settings page escapes every label with `htmlspecialchars`; JSON is sent with `nosniff` and `<`, `>`, `&`, quotes escaped. Names are normalised once, so GLPI 10's HTML-encoded names are neither double-encoded nor executed.

## Installation

1. Copy the `typingindicator` folder into `glpi/plugins/` (or `glpi/marketplace/`).
2. **Setup → Plugins** → *Typing indicator* → **Install**, then **Enable**.
3. Open the settings page and pick the profiles and groups (see above).

CLI alternative:

```bash
php bin/console glpi:plugin:install typingindicator --username=glpi
php bin/console glpi:plugin:activate typingindicator
```

## How it works

- `public/js/typingindicator.js` sends a `ping` every ~2.5 s while the user types and a `stop` after 4 s idle or on submit.
- It polls `ajax/typing.php?action=poll` every 3 s and renders the indicator.
- Data is stored in `glpi_plugin_typingindicator_typings` (one row per user/item).
- POST requests use GLPI's standard `X-Glpi-Csrf-Token` header.

## Files

```
typingindicator/
├── setup.php                  plugin declaration, hooks, version constraints
├── hook.php                   install / uninstall
├── src/Settings.php           admin settings (profiles / groups)
├── front/config.form.php      admin settings page
├── src/Typing.php             storage + message building
├── ajax/typing.php            ping / stop / poll endpoint
└── public/
    ├── js/typingindicator.js
    └── css/typingindicator.css
```

## Uninstall

Uninstalling drops the table and deletes the plugin settings.

License: GPLv3+
