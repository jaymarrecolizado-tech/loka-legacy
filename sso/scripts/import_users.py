"""
Plan #43 step 2 — copy existing users (with their bcrypt hashes) from the
LOKA and Travel Order user tables into the SSO database.

Reads both apps' DBs, merges by email, keeps both original hashes for
rollback, and picks the PRIMARY hash: LOKA's when present (LOKA is the
primary system), else Travel Order's. Never touches the source tables.

Usage (from sso/):
  SSO_DB_DSN=... python scripts/import_users.py \
      --loka-dsn mysql+pymysql://user:pass@host/dbfleet3 \
      --to-dsn   mysql+pymysql://user:pass@host/travelorder
"""
import argparse
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from sqlalchemy import create_engine, text  # noqa: E402

from app.db import SessionLocal, utcnow  # noqa: E402
from app.models import User  # noqa: E402
from app.security import hash_password  # noqa: E402


def fetch(engine, sql) -> list[dict]:
    with engine.connect() as conn:
        return [dict(r._mapping) for r in conn.execute(text(sql))]


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--loka-dsn", required=True)
    ap.add_argument("--to-dsn", required=True)
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    loka_rows = fetch(create_engine(args.loka_dsn),
                      "SELECT LOWER(TRIM(email)) AS email, name, password, status "
                      "FROM users WHERE deleted_at IS NULL")
    to_rows = fetch(create_engine(args.to_dsn),
                    "SELECT LOWER(TRIM(email)) AS email, name, password "
                    "FROM users")

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

    primary = 0
    db = SessionLocal()
    added = updated = skipped = 0
    try:
        for email, e in sorted(by_email.items()):
            hash_choice = e["loka_hash"] or e["to_hash"]
            if not hash_choice or not e["active"]:
                skipped += 1
                continue
            if not str(hash_choice).startswith("$2"):
                # Not a bcrypt hash (e.g. legacy md5) — cannot import safely.
                print(f"  SKIP non-bcrypt hash for {email}")
                skipped += 1
                continue
            if str(hash_choice) == e["loka_hash"]:
                primary += 1
            existing = db.query(User).filter(User.email == email).one_or_none()
            if args.dry_run:
                added += existing is None
                updated += existing is not None
                continue
            if existing is None:
                db.add(User(email=email, name=e["name"], password_hash=hash_choice,
                            loka_hash=e["loka_hash"], to_hash=e["to_hash"],
                            active=True, created_at=utcnow(), updated_at=utcnow()))
                added += 1
            else:
                existing.password_hash = hash_choice
                existing.loka_hash = e["loka_hash"]
                existing.to_hash = e["to_hash"]
                existing.name = e["name"] or existing.name
                updated += 1
        if not args.dry_run:
            db.commit()
    finally:
        db.close()

    print(f"users seen: {len(by_email)} | added: {added} | updated: {updated} | "
          f"skipped: {skipped} | primary hash from LOKA: {primary}")
    if not args.dry_run and added:
        print("NOTE: any account that still has a DEVELOPMENT hash needs its "
              "password reset — production hashes only exist in production DBs.")


if __name__ == "__main__":
    main()
