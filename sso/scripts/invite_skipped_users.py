"""
Plan #43 — create SSO accounts for emails skipped by import_users.py
(non-bcrypt or inactive) and issue one-time set-password links.

Does not email by itself unless SSO_SMTP_* is configured; otherwise links
are written to logs/set_password_links.log.

Usage (from sso/, with SSO_DB_DSN set):
  python scripts/invite_skipped_users.py \
      --loka-dsn mysql+pymysql://user:pass@host/db \
      --to-dsn   mysql+pymysql://user:pass@host/db
"""
import argparse
import secrets
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from sqlalchemy import create_engine, text  # noqa: E402

from app.db import SessionLocal, utcnow  # noqa: E402
from app.models import User  # noqa: E402
from app.password_reset import deliver_reset_link, issue_reset_token  # noqa: E402
from app.security import hash_password  # noqa: E402


def fetch(engine, sql) -> list[dict]:
    with engine.connect() as conn:
        return [dict(r._mapping) for r in conn.execute(text(sql))]


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--loka-dsn", default="")
    ap.add_argument("--to-dsn", default="")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()
    if not args.loka_dsn and not args.to_dsn:
        ap.error("Provide at least one of --loka-dsn / --to-dsn")

    loka_rows = []
    to_rows = []
    if args.loka_dsn:
        loka_rows = fetch(create_engine(args.loka_dsn),
                          "SELECT LOWER(TRIM(email)) AS email, name, password, status "
                          "FROM users WHERE deleted_at IS NULL")
    if args.to_dsn:
        to_rows = fetch(create_engine(args.to_dsn),
                        "SELECT LOWER(TRIM(email)) AS email, name, password FROM users")

    by_email: dict[str, dict] = {}
    for r in loka_rows:
        if not r["email"]:
            continue
        by_email[r["email"]] = {
            "email": r["email"], "name": r["name"] or r["email"],
            "loka_hash": r["password"], "to_hash": None,
            "active": (r.get("status") or "active") == "active",
        }
    for r in to_rows:
        if not r["email"]:
            continue
        entry = by_email.setdefault(r["email"], {
            "email": r["email"], "name": r["name"] or r["email"],
            "loka_hash": None, "to_hash": None, "active": True,
        })
        entry["to_hash"] = r["password"]

    skipped: list[dict] = []
    for email, e in sorted(by_email.items()):
        hash_choice = e["loka_hash"] or e["to_hash"]
        if not e["active"]:
            skipped.append({**e, "reason": "inactive"})
            continue
        if not hash_choice or not str(hash_choice).startswith("$2"):
            skipped.append({**e, "reason": "non-bcrypt"})
            continue

    print(f"skipped candidates: {len(skipped)}")
    db = SessionLocal()
    created = invited = already = 0
    try:
        for e in skipped:
            if e["reason"] == "inactive":
                print(f"  SKIP inactive {e['email']}")
                continue
            existing = db.query(User).filter(User.email == e["email"]).one_or_none()
            if args.dry_run:
                print(f"  WOULD invite {e['email']} ({'existing' if existing else 'new'})")
                continue
            if existing is None:
                # Placeholder hash the user does not know — they must use the link.
                placeholder = hash_password(secrets.token_urlsafe(24))
                existing = User(
                    email=e["email"], name=e["name"], password_hash=placeholder,
                    loka_hash=e["loka_hash"], to_hash=e["to_hash"],
                    active=True, created_at=utcnow(), updated_at=utcnow(),
                )
                db.add(existing)
                db.commit()
                db.refresh(existing)
                created += 1
            else:
                already += 1
            token = issue_reset_token(db, existing)
            channel = deliver_reset_link(existing.email, existing.name, token)
            print(f"  invited {e['email']} via {channel}")
            invited += 1
    finally:
        db.close()

    print(f"created: {created} | already in SSO: {already} | links issued: {invited}")
    if not args.dry_run and invited:
        print("If SMTP is not configured, open logs/set_password_links.log (chmod 600).")


if __name__ == "__main__":
    main()
