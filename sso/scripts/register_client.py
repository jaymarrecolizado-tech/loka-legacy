"""
Register an OIDC client (an app that logs in through SSO).

Usage (from sso/):
  python scripts/register_client.py --name "LOKA Staging" \
      --redirect-uri "https://lokastage.dictr2.cloud/?page=sso-callback" \
      [--redirect-uri ...] [--client-id loka-staging]
Prints the client_id and client_secret ONCE — only the bcrypt hash is stored.
"""
import argparse
import secrets
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app.db import SessionLocal, utcnow  # noqa: E402
from app.models import OAuthClient  # noqa: E402
from app.security import hash_password  # noqa: E402


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--name", required=True)
    ap.add_argument("--redirect-uri", action="append", required=True)
    ap.add_argument("--client-id", default=None)
    args = ap.parse_args()

    client_id = args.client_id or secrets.token_hex(4)
    secret = secrets.token_urlsafe(32)

    db = SessionLocal()
    try:
        existing = db.query(OAuthClient).filter(OAuthClient.client_id == client_id).one_or_none()
        if existing is not None:
            existing.client_secret_hash = hash_password(secret)
            existing.redirect_uris = "\n".join(args.redirect_uri)
            existing.name = args.name
            print(f"client '{client_id}' rotated: new secret + redirect URIs")
        else:
            db.add(OAuthClient(client_id=client_id, client_secret_hash=hash_password(secret),
                               name=args.name, redirect_uris="\n".join(args.redirect_uri),
                               created_at=utcnow()))
            print(f"client '{client_id}' registered")
        db.commit()
    finally:
        db.close()

    print(f"\n  client_id:     {client_id}")
    print(f"  client_secret: {secret}")
    print("\nStore it now — it is not recoverable (only the bcrypt hash is kept).")


if __name__ == "__main__":
    main()
