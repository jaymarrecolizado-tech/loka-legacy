# DICT CAR Central SSO (Plan #43)

One OpenID Connect login for LOKA (`lokafleet.dictr2.cloud`), Travel Order
(`to.dictr2.cloud`), and future dictr2 apps. FastAPI + MySQL; JWT layer is
**joserfc** (successor to `authlib.jose`, which authlib 1.8 deprecated and
broke — recorded as a deviation from the Plan #43 stack note).

## Layout
- `app/` — the service (config, models, OIDC, FastAPI routes, login template)
- `migrations/schema.sql` — apply once per environment DB (sso_dev / sso_test / sso_prod)
- `scripts/import_users.py` — copy users + bcrypt hashes from LOKA and Travel Order (read-only on sources)
- `scripts/register_client.py` — register an app; prints the client secret ONCE
- `tests/` — pytest: discovery, authorize/PKCE gates, token, userinfo, replay, lockout
- `keys/` — RS256 keypair, auto-generated on first run, **never committed**

## Local setup
```
mysql -u root -e "CREATE DATABASE sso_dev CHARACTER SET utf8mb4"
mysql -u root sso_dev < migrations/schema.sql
python scripts/register_client.py --name "LOKA local" \
    --redirect-uri "http://localhost/Projects/pred-loka-old-boots/public_html/?page=sso-callback"
# → put client_id + secret into the app's own config (public_html/config/sso.php)
python scripts/import_users.py \
    --loka-dsn mysql+pymysql://root@127.0.0.1/fleetdb \
    --to-dsn   mysql+pymysql://root@127.0.0.1/travelorder   # when available
set SSO_DB_DSN=mysql+pymysql://root@127.0.0.1:3306/sso_dev
set SSO_BASE_URL=http://localhost:8443
set SSO_APP_SECRET=<openssl rand -hex 32>
python -m uvicorn app.main:app --port 8443
```

## Tests
```
mysql -u root -e "CREATE DATABASE IF NOT EXISTS sso_test CHARACTER SET utf8mb4"
python -m pytest tests/ -q
```

## Endpoints
`/.well-known/openid-configuration` · `/authorize` (PKCE S256 required) ·
`/login` (form) · `/token` (client_secret_basic or _post) · `/userinfo` (Bearer) ·
`/jwks` · `/logout`

## Security notes
- Exact redirect-URI matching; `plain` PKCE refused; codes single-use, 60 s TTL, stored hashed.
- Lockout: 5 fails / 15 min per email or per IP (mirror of LOKA `Auth.php` policy), fail-closed.
- Audit rows for login_ok/fail/locked, code_issued, token_issued, replay attempts, logout.
- Client secrets stored bcrypt-hashed; RS256 private key file-local, gitignored.
