# Upgrading

Breaking changes between versions, newest first, with what to change in a
consuming plugin. Within a major version releases are additive, so this file
only gains an entry when the major version changes.

## 1.2.0

Additive; nothing to change. Two behaviours to know:

- The `bitapps_ai_rest_permission_<slug>` filter no longer runs for signed-out requests (they get 401 first)
  and receives a fourth argument, the route's level. A callback that accepted three arguments keeps working.
- An activity-log switch that is off now also stops a logger passed in `services.audit`.

To use the UI package, see `docs/ADOPTION.md` ("Admin UI") and `docs/RELEASING.md` ("Upgrade a consumer").

## 1.0.0

First release. No earlier version to upgrade from.
