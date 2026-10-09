"""Shared fixtures: sso_test database, seeded client + user, test client."""
import base64
import hashlib
import os
import secrets
import sys
from pathlib import Path

import bcrypt
import pytest

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
os.environ["SSO_DB_DSN"] = os.environ.get("SSO_DB_DSN", "mysql+pymysql://root@127.0.0.1:3306/sso_test")
os.environ.setdefault("SSO_BASE_URL", "https://sso.dictr2.cloud")
os.environ.setdefault("SSO_APP_SECRET", "test-secret-not-for-prod")

from app.db import Base, SessionLocal, engine, utcnow  # noqa: E402
from app.main import app  # noqa: E402
from app.models import OAuthClient, User  # noqa: E402
from app.oidc import ensure_keys  # noqa: E402
from app.security import hash_password  # noqa: E402

TEST_PASSWORD = "sso-test-password"
CLIENT_ID = "test-client"
CLIENT_SECRET = "test-client-secret"
REDIRECT_URI = "https://app.example.test/callback"


def make_pkce() -> tuple[str, str]:
    verifier = base64.urlsafe_b64encode(secrets.token_bytes(32)).decode().rstrip("=")
    challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).decode().rstrip("=")
    return verifier, challenge


@pytest.fixture(scope="session", autouse=True)
def database():
    """Create schema once per session; drop at the end."""
    ensure_keys()
    Base.metadata.drop_all(engine)
    Base.metadata.create_all(engine)
    db = SessionLocal()
    try:
        # PHP-style $2y$ hash to prove cross-compat (Plan #43 decision).
        php_hash = bcrypt.hashpw(TEST_PASSWORD.encode(), bcrypt.gensalt(prefix=b"2a")).decode()
        php_hash = "$2y$" + php_hash[4:]
        db.add(User(email="worker@dict.gov.ph", name="Test Worker",
                    password_hash=php_hash, loka_hash=php_hash, to_hash=None,
                    active=True, created_at=utcnow(), updated_at=utcnow()))
        db.add(User(email="locked@dict.gov.ph", name="Lock Me",
                    password_hash=hash_password(TEST_PASSWORD), active=True,
                    created_at=utcnow(), updated_at=utcnow()))
        db.add(OAuthClient(client_id=CLIENT_ID,
                           client_secret_hash=hash_password(CLIENT_SECRET),
                           name="Test App", redirect_uris=REDIRECT_URI + "\n" + REDIRECT_URI + "?x=1",
                           created_at=utcnow()))
        db.commit()
    finally:
        db.close()
    yield
    Base.metadata.drop_all(engine)


@pytest.fixture()
def db_session():
    db = SessionLocal()
    try:
        yield db
    finally:
        db.rollback()
        db.close()


@pytest.fixture()
def client():
    from fastapi.testclient import TestClient
    with TestClient(app) as c:
        yield c


def authorize_start(client, state="st-123", nonce="n-456", redirect_uri=REDIRECT_URI,
                    client_id=CLIENT_ID, challenge=None):
    verifier, challenge = make_pkce() if challenge is None else ("v", challenge)
    resp = client.get("/authorize", params={
        "response_type": "code", "client_id": client_id, "redirect_uri": redirect_uri,
        "scope": "openid profile email", "state": state, "nonce": nonce,
        "code_challenge": challenge, "code_challenge_method": "S256",
    }, follow_redirects=False)
    return resp, verifier
