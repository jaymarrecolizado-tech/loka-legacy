"""One-time set-password / forgot-password helpers (Plan #43)."""
import secrets
import smtplib
from datetime import timedelta
from email.message import EmailMessage
from pathlib import Path

from sqlalchemy import select
from sqlalchemy.orm import Session

from .config import settings
from .db import utcnow
from .models import PasswordResetToken, User
from .security import audit, hash_password, sha256_hex


MIN_PASSWORD_LEN = 8


def validate_password(password: str) -> str | None:
    if len(password) < MIN_PASSWORD_LEN:
        return f"Password must be at least {MIN_PASSWORD_LEN} characters."
    return None


def issue_reset_token(db: Session, user: User, ip: str | None = None) -> str:
    """Create a single-use token (returns the plaintext once)."""
    token = secrets.token_urlsafe(32)
    now = utcnow()
    db.add(PasswordResetToken(
        user_id=user.id,
        token_hash=sha256_hex(token),
        expires_at=now + timedelta(seconds=settings.reset_ttl_seconds),
        created_at=now,
    ))
    audit(db, "password_reset_issued", user_id=user.id, ip=ip)
    db.commit()
    return token


def reset_link(token: str) -> str:
    return f"{settings.base_url}/set-password?token={token}"


def find_valid_token(db: Session, token: str) -> PasswordResetToken | None:
    if not token or len(token) < 20:
        return None
    row = db.execute(
        select(PasswordResetToken).where(PasswordResetToken.token_hash == sha256_hex(token))
    ).scalar_one_or_none()
    if row is None or row.used_at is not None:
        return None
    if row.expires_at < utcnow():
        return None
    return row


def apply_password(db: Session, row: PasswordResetToken, new_password: str,
                   ip: str | None = None) -> User | None:
    user = db.get(User, row.user_id)
    if user is None or not user.active:
        return None
    user.password_hash = hash_password(new_password)
    user.failed_attempts = 0
    user.locked_until = None
    user.updated_at = utcnow()
    row.used_at = utcnow()
    audit(db, "password_reset_completed", user_id=user.id, ip=ip)
    db.commit()
    return user


def deliver_reset_link(email: str, name: str, token: str) -> str:
    """
    Email the link when SMTP is configured; otherwise append to a local log.
    Returns 'email' or 'log'.
    """
    link = reset_link(token)
    if settings.smtp_host:
        msg = EmailMessage()
        msg["Subject"] = "Set your DICT Region 2 SSO password"
        msg["From"] = settings.smtp_from
        msg["To"] = email
        msg.set_content(
            f"Hello {name or email},\n\n"
            f"Use this one-time link to set your SSO password (expires in "
            f"{settings.reset_ttl_seconds // 60} minutes):\n\n{link}\n\n"
            f"If you did not request this, ignore this email.\n"
        )
        with smtplib.SMTP(settings.smtp_host, settings.smtp_port, timeout=20) as smtp:
            smtp.starttls()
            if settings.smtp_user:
                smtp.login(settings.smtp_user, settings.smtp_password)
            smtp.send_message(msg)
        return "email"

    log_path = Path(__file__).resolve().parent.parent / "logs" / "set_password_links.log"
    log_path.parent.mkdir(parents=True, exist_ok=True)
    with log_path.open("a", encoding="utf-8") as fh:
        fh.write(f"{utcnow().isoformat()}Z\t{email}\t{link}\n")
    try:
        log_path.chmod(0o600)
    except OSError:
        pass
    return "log"
