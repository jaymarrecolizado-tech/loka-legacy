"""Password verification, lockout, audit, and cookie signing."""
import base64
import hashlib
import hmac
import json
from datetime import timedelta

import bcrypt

from .config import settings
from .db import utcnow
from .models import LoginAttempt, OAuthAudit, User
from sqlalchemy import select, update


def verify_password(plain: str, password_hash: str) -> bool:
    """Accepts $2y$ (PHP password_hash) and $2b$/$2a$ bcrypt hashes."""
    if not password_hash:
        return False
    h = password_hash.encode()
    if h.startswith(b"$2y$"):
        h = b"$2b$" + h[4:]
    try:
        return bcrypt.checkpw(plain.encode(), h)
    except (ValueError, TypeError):
        return False


def verify_user_password(user: User, plain: str) -> bool:
    """
    Try the primary hash, then the per-app originals (Plan #43 keeps both).
    Users often type the password from the app they just came from.
    """
    candidates = [user.password_hash, user.loka_hash, user.to_hash]
    seen: set[str] = set()
    for h in candidates:
        if not h or h in seen:
            continue
        seen.add(h)
        if verify_password(plain, h):
            return True
    return False


def hash_password(plain: str) -> str:
    return bcrypt.hashpw(plain.encode(), bcrypt.gensalt()).decode()


def sha256_hex(value: str) -> str:
    return hashlib.sha256(value.encode()).hexdigest()


# ---- lockout ---------------------------------------------------------------

def _fail_count(db, identifier: str) -> int:
    since = utcnow() - timedelta(minutes=settings.lockout_window_minutes)
    rows = db.execute(
        select(LoginAttempt)
        .where(LoginAttempt.identifier == identifier,
               LoginAttempt.success.is_(False),
               LoginAttempt.created_at >= since)
    ).scalars().all()
    return len(rows)


def is_locked(db, user: User | None, ip: str) -> bool:
    """Per-account lock (failed_attempts on the user row) + per-IP lock."""
    now = utcnow()
    if user is not None and user.locked_until is not None and user.locked_until > now:
        return True
    ip_key = f"ip:{ip}"
    if user is None and _fail_count(db, ip_key) >= settings.lockout_threshold:
        return True
    if user is not None and _fail_count(db, ip_key) >= settings.lockout_threshold * 3:
        return True
    return False


def record_attempt(db, identifier: str, success: bool, ip: str, user: User | None) -> None:
    db.add(LoginAttempt(identifier=identifier, success=success, ip=ip, created_at=utcnow()))
    if user is None:
        return
    if success:
        db.execute(
            update(User).where(User.id == user.id)
            .values(failed_attempts=0, locked_until=None)
        )
        return
    fails = user.failed_attempts + 1
    values: dict = {"failed_attempts": fails}
    if fails >= settings.lockout_threshold:
        # Also count recent failures across the window for the repeat case
        values["locked_until"] = utcnow() + timedelta(minutes=settings.lockout_minutes)
    db.execute(update(User).where(User.id == user.id).values(**values))


# ---- audit ------------------------------------------------------------------

def audit(db, event: str, user_id: int | None = None, client_id: str | None = None,
          ip: str | None = None, detail: str | None = None) -> None:
    db.add(OAuthAudit(event=event, user_id=user_id, client_id=client_id, ip=ip,
                      detail=(detail or "")[:500], created_at=utcnow()))


# ---- signed cookies (HMAC-SHA256, no extra dependency) -----------------------

def _sign(payload: bytes) -> str:
    sig = hmac.new(settings.app_secret.encode(), payload, hashlib.sha256).hexdigest()
    return sig


def sign_cookie(name: str, data: dict, ttl_seconds: int) -> str:
    body = json.dumps({"d": data, "e": int(utcnow().timestamp()) + ttl_seconds},
                      separators=(",", ":"), sort_keys=True).encode()
    b64 = base64.urlsafe_b64encode(body).decode().rstrip("=")
    return f"{b64}.{_sign(body)}"


def read_cookie(name: str, value: str) -> dict | None:
    try:
        b64, sig = value.split(".", 1)
        body = base64.urlsafe_b64decode(b64 + "=" * (-len(b64) % 4))
        if not hmac.compare_digest(_sign(body), sig):
            return None
        data = json.loads(body)
        if int(data.get("e", 0)) < int(utcnow().timestamp()):
            return None
        return data.get("d")
    except Exception:
        return None
