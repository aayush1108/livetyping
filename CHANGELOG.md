# Changelog

## 1.1.0 - hardening and resilience release

Server
- Rate limit is now a fixed window per session (10 requests / 5 s) shared by all tabs, instead of one limit per
  action. Several open tickets no longer collide; a script hammering the endpoint is still stopped, and a limited
  request still costs zero database queries.
- A user can only hold the rows of the last 10 seconds: their expired rows are removed on every "typing" signal
  (new index `users_id`, added automatically on upgrade through GLPI's Migration API).

Browser script
- An error while handling or drawing can no longer stall polling.
- Temporary trouble (network loss, server errors, repeated "slow down") now pauses instead of stopping for good,
  and resumes by itself when the tab is shown, the network is back, the page is restored from the back/forward
  cache, or a typing signal succeeds. It still stops permanently on 401/403/404/405 and when the login session is
  gone (redirect to the login page).
- "Retry-After" from the server is honoured.
- If the server stops answering, a "typing" label is hidden after 15 s instead of staying on screen (it comes back
  by itself when the server is back). Verified with a real browser while the server was killed and restarted.
- Small random jitter (+-10 %) on the refresh interval so browsers do not all call at the same instant.
- Screen readers no longer re-announce an unchanged sentence at every refresh.
- Silently does nothing on browsers without fetch/Promise/URL.

Other
- Added SECURITY.md (threat model, how to report) and a translation template (locales/livetyping.pot).
- Pill has a thin border so it stays visible on dark themes.

## 1.0.0

First release.
