"""OIDC primitives: RSA keys, JWT signing/verification, JWKS, PKCE.

JWT layer uses joserfc — the successor to authlib.jose, which authlib 1.8
deprecated and broke (UnsupportedAlgorithmError on encode). Deviation from
the "Authlib" plan note recorded in Plan.md.
"""
import base64
import hashlib
import hmac
import time
import uuid
from pathlib import Path

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from joserfc import jwt as rfc_jwt
from joserfc.errors import BadSignatureError
from joserfc.jwk import RSAKey

from .config import settings


# ---- key management ---------------------------------------------------------

def _private_pem_path() -> Path:
    return settings.keys_dir / "sso_rs256_private.pem"


def ensure_keys() -> None:
    """Generate the RS256 keypair on first run (never committed)."""
    settings.keys_dir.mkdir(parents=True, exist_ok=True)
    priv = _private_pem_path()
    if not priv.exists():
        key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
        priv.write_bytes(key.private_bytes(
            serialization.Encoding.PEM,
            serialization.PrivateFormat.PKCS8,
            serialization.NoEncryption(),
        ))
        (settings.keys_dir / "sso_rs256_public.pem").write_bytes(key.public_key().public_bytes(
            serialization.Encoding.PEM,
            serialization.PublicFormat.SubjectPublicKeyInfo,
        ))


def _private_pem() -> bytes:
    ensure_keys()
    return _private_pem_path().read_bytes()


def _public_pem() -> bytes:
    ensure_keys()
    return (settings.keys_dir / "sso_rs256_public.pem").read_bytes()


def kid() -> str:
    """Stable key id: SHA256 of the public PEM, first 16 hex chars."""
    return hashlib.sha256(_public_pem()).hexdigest()[:16]


def jwks() -> dict:
    jwk = RSAKey.import_key(_public_pem()).as_dict()
    jwk.update({"kid": kid(), "use": "sig", "alg": "RS256"})
    return {"keys": [{k: jwk[k] for k in ("kty", "n", "e", "kid", "use", "alg") if k in jwk}]}


# ---- tokens ------------------------------------------------------------------

def issue_jwt(claims: dict, ttl_seconds: int) -> str:
    header = {"alg": "RS256", "kid": kid(), "typ": "JWT"}
    now = int(time.time())
    payload = {"iss": settings.issuer, "iat": now, "exp": now + ttl_seconds, **claims}
    return rfc_jwt.encode(header, payload, RSAKey.import_key(_private_pem()))


def verify_jwt(token: str) -> dict | None:
    try:
        result = rfc_jwt.decode(token, RSAKey.import_key(_public_pem()))
        claims = dict(result.claims)
        if int(claims.get("exp", 0)) < time.time():
            return None
        return claims
    except (BadSignatureError, ValueError, TypeError):
        return None


def id_token_claims(user, client_id: str, nonce: str | None, auth_time: int) -> dict:
    claims = {
        "sub": str(user.id),
        "aud": client_id,
        "nonce": nonce,
        "auth_time": auth_time,
        "email": user.email,
        "name": user.name,
        "email_verified": True,
    }
    return {k: v for k, v in claims.items() if v is not None}


def access_token_claims(user, client_id: str, scope: str) -> dict:
    return {
        "sub": str(user.id),
        "aud": client_id,
        "scope": scope,
        "jti": uuid.uuid4().hex,
        "email": user.email,
        "name": user.name,
    }


# ---- PKCE --------------------------------------------------------------------

def pkce_valid(verifier: str, challenge: str, method: str) -> bool:
    """RFC 7636 — only S256 is accepted (plain is refused by the authorize gate)."""
    if method != "S256" or not verifier or not challenge:
        return False
    try:
        digest = hashlib.sha256(verifier.encode("ascii")).digest()
    except UnicodeEncodeError:
        return False
    computed = base64.urlsafe_b64encode(digest).decode().rstrip("=")
    return hmac.compare_digest(computed.encode(), challenge.encode())
