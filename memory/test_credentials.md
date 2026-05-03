# SpiderNetOS Cockpit — Test credentials

The cockpit runs against a FastAPI mock backend at `/app/backend/server.py`.

## Any credentials are accepted

The `POST /api/auth/login` endpoint accepts **any non-empty email + password**
pair and issues a signed mock token. The desired role can be selected on the
login card or swapped later from the user menu.

## Recommended demo principals

| Email | Password | Role |
| --- | --- | --- |
| `operator@acme.ops` | `demo` | `super_admin` (default on login card) |
| `admin@acme.ops`    | `demo` | `admin` |
| `user@acme.ops`     | `demo` | `user` |

After login the user lands on `/` (Dashboard). The user menu in the top-right
exposes a "Demo: Switch Role" picker with User / Admin / Super that re-derives
capabilities client-side — useful for demoing the IA differences without
re-authenticating.

## API base

- Same-origin via ingress: `https://spidernet-cockpit.preview.emergentagent.com/api/*`
- Direct backend: `http://localhost:8001/api/*`
