"""Plan #43 — SSO service configuration (env-driven, no secrets in code)."""
import json
import os
from pathlib import Path


def _env(key: str, default: str = "") -> str:
    return os.environ.get(key, default).strip()


def _default_apps() -> list[dict]:
    """App launcher cards shown after portal login at /."""
    return [
        {
            "id": "loka",
            "name": "LOKA Fleet",
            "description": "Vehicle requests, trip tickets, gas vouchers, and motorpool.",
            "url": _env("SSO_APP_LOKA_URL", "https://lokastage.dictr2.cloud"),
        },
        {
            "id": "to",
            "name": "Travel Order",
            "description": "Request, approve, and issue official Travel Orders.",
            "url": _env("SSO_APP_TO_URL", "https://to.dictr2.cloud/DICT"),
        },
    ]


class Settings:
    # mysql+pymysql://user:pass@host:3306/dbname
    db_dsn: str = _env("SSO_DB_DSN", "mysql+pymysql://root@127.0.0.1:3306/sso_dev")
    base_url: str = _env("SSO_BASE_URL", "https://sso.dictr2.cloud").rstrip("/")
    # 64+ hex chars; HMAC-signs the transient auth-request + session cookies
    app_secret: str = _env("SSO_APP_SECRET", "dev-only-secret-change-me")
    keys_dir: Path = Path(_env("SSO_KEYS_DIR", str(Path(__file__).resolve().parent.parent / "keys")))

    code_ttl_seconds: int = int(_env("SSO_CODE_TTL", "60"))
    access_ttl_seconds: int = int(_env("SSO_ACCESS_TTL", "600"))
    id_token_ttl_seconds: int = int(_env("SSO_ID_TOKEN_TTL", "3600"))
    session_ttl_seconds: int = int(_env("SSO_SESSION_TTL", str(8 * 3600)))
    auth_req_ttl_seconds: int = int(_env("SSO_AUTH_REQ_TTL", "600"))

    # Lockout: N failed attempts for one email (or one IP) inside the window
    # locks that identifier for lockout_minutes. Mirrors LOKA Auth.php policy.
    lockout_threshold: int = int(_env("SSO_LOCKOUT_THRESHOLD", "5"))
    lockout_window_minutes: int = int(_env("SSO_LOCKOUT_WINDOW", "15"))
    lockout_minutes: int = int(_env("SSO_LOCKOUT_MINUTES", "15"))

    issuer: str = _env("SSO_ISSUER", base_url)

    # One-time set-password / forgot-password (Plan #43).
    reset_ttl_seconds: int = int(_env("SSO_RESET_TTL", "3600"))  # 1 hour
    # When set, forgot-password emails the link. Otherwise the link is written
    # to logs/set_password_links.log (chmod 600) for an admin to deliver.
    smtp_host: str = _env("SSO_SMTP_HOST", "")
    smtp_port: int = int(_env("SSO_SMTP_PORT", "587"))
    smtp_user: str = _env("SSO_SMTP_USER", "")
    smtp_password: str = _env("SSO_SMTP_PASSWORD", "")
    smtp_from: str = _env("SSO_SMTP_FROM", "noreply@dictr2.cloud")

    # Portal app launcher (JSON array overrides the defaults above).
    # Example: [{"id":"loka","name":"LOKA Fleet","description":"...","url":"https://..."}]
    @property
    def portal_apps(self) -> list[dict]:
        raw = _env("SSO_PORTAL_APPS", "")
        if raw:
            try:
                data = json.loads(raw)
                if isinstance(data, list) and data:
                    return data
            except json.JSONDecodeError:
                pass
        return _default_apps()


settings = Settings()
