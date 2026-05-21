# UAT Gate 1 — Zero Events After Strip

**Date:** _<YYYY-MM-DD HH:MM operator timezone>_
**Env:** https://new.nailscosmetics.lv
**Deploy SHA (theme repo):** _bda69f8 or later (08afc24 includes rebuilt bundle)_
**Operator:** _<name>_

## Three-source verdict per page

| Page | HTTP status | Pixel Helper count | Test Events count | EventLog count | Verdict |
|------|-------------|---------------------|-------------------|----------------|---------|
| / | _200_ | _0_ | _0_ | _0_ | _PASS_ |
| /catalog | _…_ | _…_ | _…_ | _…_ | _…_ |
| /product/<slug> | _…_ | _…_ | _…_ | _…_ | _…_ |
| /checkout | _…_ | _…_ | _…_ | _…_ | _…_ |
| /order-complete | _…_ | _…_ | _…_ | _…_ | _…_ |

## Anomalies (if any)

_<free-form notes per page — anomaly description, expected vs actual, screenshot path if helpful>_

## Overall verdict

_GATE 1 PASS_  (or  _GATE 1 FAIL — see Anomalies_)
