"""
Plan #43 — Central SSO service (FastAPI + Authlib + MySQL).

OIDC authorization-code flow with PKCE (S256 only), state, and nonce.
Apps authenticate at /token with client_secret_basic or _post; users are
matched by email; roles stay in the apps.

Run (dev):  uvicorn app.main:app --reload --port 8443
"""
from datetime import timedelta
from urllib.parse import urlencode, urlparse

from fastapi import Depends, FastAPI, Form, Query, Request
from fastapi.responses import HTMLResponse, JSONResponse, RedirectResponse
from fastapi.templating import Jinja2Templates
from sqlalchemy import select
from sqlalchemy.orm import Session

from .config import settings
from .db import SessionLocal, utcnow
from .models import OAuthClient, OAuthCode, User
from .oidc import (access_token_claims, id_token_claims, issue_jwt,
                   jwks, kid, pkce_valid, verify_jwt)
from .security import (audit, hash_password, is_locked, read_cookie, record_attempt,
                       sha256_hex, sign_cookie, verify_password)

app = FastAPI(title="DICT CAR SSO", docs_url=None, redoc_url=None)
templates = Jinja2Templates(directory="app/templates")

AUTH_REQ_COOKIE = "sso_auth_req"
SESSION_COOKIE = "sso_session"
SUPPORTED_SCOPES = {"openid", "profile", "email"}


def get_db():
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()


def client_ip(request: Request) -> str:
    fwd = request.headers.get("x-forwarded-for", "")
    return (fwd.split(",")[0].strip() if fwd else request.client.host) if request.client else "0.0.0.0"


# ---------------------------------------------------------------------------
# discovery + keys
# ---------------------------------------------------------------------------

@app.get("/.well-known/openid-configuration")
def discovery():
    b = settings.base_url
    return JSONResponse({
        "issuer": settings.issuer,
        "authorization_endpoint": f"{b}/authorize",
        "token_endpoint": f"{b}/token",
        "userinfo_endpoint": f"{b}/userinfo",
        "jwks_uri": f"{b}/jwks",
        "end_session_endpoint": f"{b}/logout",
        "response_types_supported": ["code"],
        "subject_types_supported": ["public"],
        "id_token_signing_alg_values_supported": ["RS256"],
        "scopes_supported": sorted(SUPPORTED_SCOPES),
        "token_endpoint_auth_methods_supported": ["client_secret_basic", "client_secret_post"],
        "code_challenge_methods_supported": ["S256"],
        "claims_supported": ["sub", "iss", "aud", "exp", "iat", "nonce", "email", "name"],
    })


@app.get("/jwks")
def well_known_jwks():
    return JSONResponse(jwks())


# ---------------------------------------------------------------------------
# authorize — validate the client request, then show login (or fast-path)
# ---------------------------------------------------------------------------

def _validate_authorize(db: Session, request: Request) -> tuple[OAuthClient | None, str | None, dict | None]:
    """Returns (client, error_redirect_url_or_message, auth_request)."""
    client_id = request.query_params.get("client_id", "")
    redirect_uri = request.query_params.get("redirect_uri", "")
    response_type = request.query_params.get("response_type", "")
    scope = request.query_params.get("scope", "openid")
    state = request.query_params.get("state", "")
    code_challenge = request.query_params.get("code_challenge", "")
    method = request.query_params.get("code_challenge_method", "")

    def err(msg: str, status: int = 400) -> tuple[None, str, None]:
        return None, msg, None

    client = db.execute(
        select(OAuthClient).where(OAuthClient.client_id == client_id, OAuthClient.enabled.is_(True))
    ).scalar_one_or_none()
    if client is None:
        return err("Unknown client_id.")
    # Exact-match redirect URI: no prefix, no wildcard, no fuzz.
    if redirect_uri not in client.redirect_list():
        return err("redirect_uri is not registered for this client.")
    if response_type != "code":
        return err("Only response_type=code is supported.")
    if not code_challenge:
        return err("PKCE code_challenge is required.")
    if method != "S256":
        return err("Only code_challenge_method=S256 is supported.")
    requested = [s for s in scope.split() if s]
    if not requested or not set(requested).issubset(SUPPORTED_SCOPES):
        return err("scope must be a subset of: openid profile email")
    if "openid" not in requested:
        return err("scope must include openid.")

    auth_req = {
        "client_id": client_id,
        "redirect_uri": redirect_uri,
        "state": state,
        "nonce": request.query_params.get("nonce", ""),
        "scope": " ".join(requested),
        "code_challenge": code_challenge,
        "method": method,
    }
    return client, None, auth_req


def _append_param(uri: str, params: dict) -> str:
    sep = "&" if "?" in uri else "?"
    return f"{uri}{sep}{urlencode(params)}"


def _issue_code(db: Session, user: User, req: dict, ip: str) -> str:
    import secrets
    code = secrets.token_urlsafe(32)
    now = utcnow()
    db.add(OAuthCode(
        code_hash=sha256_hex(code),
        client_id=req["client_id"],
        user_id=user.id,
        redirect_uri=req["redirect_uri"],
        scope=req["scope"],
        nonce=req.get("nonce") or None,
        code_challenge=req["code_challenge"],
        code_challenge_method=req["method"],
        expires_at=now + timedelta(seconds=settings.code_ttl_seconds),
        created_at=now,
    ))
    audit(db, "code_issued", user_id=user.id, client_id=req["client_id"], ip=ip)
    db.commit()
    return code


@app.get("/authorize")
def authorize(request: Request, db: Session = Depends(get_db)):
    client, error, auth_req = _validate_authorize(db, request)
    if error:
        return HTMLResponse(f"<h1>SSO error</h1><p>{error}</p>", status_code=400)

    # Existing SSO session → fast path, no login prompt.
    session = read_cookie(SESSION_COOKIE, request.cookies.get(SESSION_COOKIE, ""))
    if session:
        user = db.get(User, int(session["u"]))
        if user is not None and user.active:
            code = _issue_code(db, user, auth_req, client_ip(request))
            params = {"code": code}
            if auth_req["state"]:
                params["state"] = auth_req["state"]
            return RedirectResponse(_append_param(auth_req["redirect_uri"], params), status_code=302)

    cookie = sign_cookie(AUTH_REQ_COOKIE, auth_req, settings.auth_req_ttl_seconds)
    resp = templates.TemplateResponse(request, "login.html", {
        "error": None, "app_name": client.name,
    })
    resp.set_cookie(AUTH_REQ_COOKIE, cookie, httponly=True, samesite="lax",
                    secure=request.url.scheme == "https", max_age=settings.auth_req_ttl_seconds)
    return resp


# ---------------------------------------------------------------------------
# login POST
# ---------------------------------------------------------------------------

@app.post("/login")
def login(request: Request, db: Session = Depends(get_db),
          email: str = Form(""), password: str = Form("")):
    req_cookie = request.cookies.get(AUTH_REQ_COOKIE, "")
    auth_req = read_cookie(AUTH_REQ_COOKIE, req_cookie)
    if not auth_req:
        return HTMLResponse("<h1>SSO error</h1><p>Login request expired. Restart from the app.</p>", status_code=400)

    ip = client_ip(request)
    email_norm = email.strip().lower()
    user = db.execute(select(User).where(User.email == email_norm)).scalar_one_or_none()

    locked = is_locked(db, user, ip)
    if locked:
        record_attempt(db, email_norm or f"ip:{ip}", False, ip, user)
        audit(db, "login_locked", user_id=user.id if user else None, ip=ip)
        db.commit()
        return templates.TemplateResponse(request, "login.html", {
            "error": "Too many failed attempts. This account is temporarily locked. Try again later.",
            "app_name": auth_req.get("client_id", ""),
        }, status_code=429)

    password_ok = user is not None and user.active and verify_password(password, user.password_hash)
    record_attempt(db, email_norm, password_ok, ip, user)
    if not password_ok:
        audit(db, "login_fail", user_id=user.id if user else None, ip=ip,
              detail="inactive account" if user is not None and not user.active else None)
        db.commit()
        return templates.TemplateResponse(request, "login.html", {
            "error": "Wrong email or password.", "app_name": auth_req.get("client_id", ""),
        }, status_code=401)

    audit(db, "login_ok", user_id=user.id, ip=ip)
    db.commit()
    auth_time = int(utcnow().timestamp())
    code = _issue_code(db, user, auth_req, ip)
    params = {"code": code}
    if auth_req.get("state"):
        params["state"] = auth_req["state"]

    session_value = sign_cookie(SESSION_COOKIE, {"u": user.id, "t": auth_time},
                                settings.session_ttl_seconds)
    resp = RedirectResponse(_append_param(auth_req["redirect_uri"], params), status_code=302)
    resp.set_cookie(SESSION_COOKIE, session_value, httponly=True, samesite="lax",
                    secure=request.url.scheme == "https", max_age=settings.session_ttl_seconds)
    resp.delete_cookie(AUTH_REQ_COOKIE)
    return resp


# ---------------------------------------------------------------------------
# token
# ---------------------------------------------------------------------------

def _client_from_request(db: Session, request: Request, client_id_form: str,
                         client_secret_form: str) -> OAuthClient | None:
    auth = request.headers.get("Authorization", "")
    cid, csec = "", ""
    if auth.startswith("Basic "):
        import base64
        try:
            raw = base64.b64decode(auth[6:]).decode()
            cid, _, csec = raw.partition(":")
        except Exception:
            return None
    else:
        cid, csec = client_id_form, client_secret_form
    if not cid:
        return None
    client = db.execute(
        select(OAuthClient).where(OAuthClient.client_id == cid, OAuthClient.enabled.is_(True))
    ).scalar_one_or_none()
    if client is None:
        return None
    if not verify_password(csec, client.client_secret_hash):
        return None
    return client


@app.post("/token")
def token(request: Request, db: Session = Depends(get_db),
          grant_type: str = Form(""),
          code: str = Form(""),
          redirect_uri: str = Form(""),
          client_id: str = Form(""),
          client_secret: str = Form(""),
          code_verifier: str = Form("")):
    client = _client_from_request(db, request, client_id, client_secret)
    if client is None:
        return JSONResponse({"error": "invalid_client"}, status_code=401)
    if grant_type != "authorization_code":
        return JSONResponse({"error": "unsupported_grant_type"}, status_code=400)

    code_row = db.execute(
        select(OAuthCode).where(OAuthCode.code_hash == sha256_hex(code))
    ).scalar_one_or_none()
    if code_row is None or code_row.used_at is not None:
        audit(db, "token_replay_or_unknown", client_id=client.client_id, ip=client_ip(request))
        db.commit()
        return JSONResponse({"error": "invalid_grant", "error_description": "code unknown or already used"}, status_code=400)
    if code_row.client_id != client.client_id:
        return JSONResponse({"error": "invalid_grant", "error_description": "code was issued to another client"}, status_code=400)
    if code_row.redirect_uri != redirect_uri:
        return JSONResponse({"error": "invalid_grant", "error_description": "redirect_uri mismatch"}, status_code=400)
    if code_row.expires_at < utcnow():
        return JSONResponse({"error": "invalid_grant", "error_description": "code expired"}, status_code=400)
    if not pkce_valid(code_verifier, code_row.code_challenge, code_row.code_challenge_method):
        return JSONResponse({"error": "invalid_grant", "error_description": "PKCE verification failed"}, status_code=400)

    code_row.used_at = utcnow()
    user = db.get(User, code_row.user_id)
    if user is None or not user.active:
        db.commit()
        return JSONResponse({"error": "invalid_grant"}, status_code=400)

    auth_time = int(code_row.created_at.replace(tzinfo=None).timestamp())
    scope = code_row.scope
    access = issue_jwt(access_token_claims(user, client.client_id, scope), settings.access_ttl_seconds)
    idt = issue_jwt(id_token_claims(user, client.client_id, code_row.nonce, auth_time),
                    settings.id_token_ttl_seconds)

    audit(db, "token_issued", user_id=user.id, client_id=client.client_id, ip=client_ip(request))
    db.commit()
    return JSONResponse({
        "access_token": access,
        "id_token": idt,
        "token_type": "Bearer",
        "expires_in": settings.access_ttl_seconds,
        "scope": scope,
    })


# ---------------------------------------------------------------------------
# userinfo
# ---------------------------------------------------------------------------

@app.get("/userinfo")
def userinfo(request: Request, db: Session = Depends(get_db)):
    auth = request.headers.get("Authorization", "")
    if not auth.startswith("Bearer "):
        return JSONResponse({"error": "invalid_token"}, status_code=401,
                            headers={"WWW-Authenticate": "Bearer"})
    claims = verify_jwt(auth[7:])
    if claims is None:
        return JSONResponse({"error": "invalid_token"}, status_code=401)
    user = db.get(User, int(claims["sub"]))
    if user is None or not user.active:
        return JSONResponse({"error": "invalid_token"}, status_code=401)
    out = {"sub": str(user.id), "email": user.email, "name": user.name}
    if "email" in (claims.get("scope", "")):
        out["email_verified"] = True
    return JSONResponse(out)


# ---------------------------------------------------------------------------
# logout — front-channel; back-channel deferred (Plan #43 step 6)
# ---------------------------------------------------------------------------

@app.get("/logout")
@app.post("/logout")
def logout(request: Request, db: Session = Depends(get_db),
           id_token_hint: str = Query("") ,
           post_logout_redirect_uri: str = Query("")):
    hint_claims = verify_jwt(id_token_hint) if id_token_hint else None
    if hint_claims:
        client = db.execute(select(OAuthClient).where(
            OAuthClient.client_id == hint_claims.get("aud"))).scalar_one_or_none()
        if client is not None and post_logout_redirect_uri:
            if post_logout_redirect_uri in client.redirect_list():
                resp = RedirectResponse(post_logout_redirect_uri, status_code=302)
                resp.delete_cookie(SESSION_COOKIE)
                audit(db, "logout", user_id=int(hint_claims["sub"]), client_id=client.client_id)
                db.commit()
                return resp
    audit(db, "logout")
    db.commit()
    resp = HTMLResponse("<h1>Signed out</h1><p>You can close this window and return to the app.</p>")
    resp.delete_cookie(SESSION_COOKIE)
    return resp
