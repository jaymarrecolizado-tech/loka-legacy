"""Plan #43 QA — discovery, authorize/PKCE, token, userinfo, replay, lockout."""
import base64
import hashlib
import re

from tests.conftest import (CLIENT_ID, CLIENT_SECRET, REDIRECT_URI, TEST_PASSWORD,
                            authorize_start, make_pkce)


def parse_redirect(resp):
    loc = resp.headers["location"]
    assert loc.startswith(REDIRECT_URI), loc
    qs = loc.split("?", 1)[1]
    return dict(p.split("=", 1) for p in qs.split("&"))


def full_login(client, email="worker@dict.gov.ph", password=TEST_PASSWORD, **kw):
    resp, verifier = authorize_start(client, **kw)
    assert resp.status_code == 200, resp.text  # login form rendered
    form = client.post("/login", data={"email": email, "password": password},
                       follow_redirects=False)
    return form, verifier


# ---- discovery + jwks -------------------------------------------------------

def test_discovery(client):
    d = client.get("/.well-known/openid-configuration").json()
    assert d["issuer"] == "https://sso.dictr2.cloud"
    assert d["authorization_endpoint"].endswith("/authorize")
    assert "S256" in d["code_challenge_methods_supported"]
    assert "RS256" in d["id_token_signing_alg_values_supported"]


def test_jwks_has_rs256_key(client):
    keys = client.get("/jwks").json()["keys"]
    assert len(keys) == 1 and keys[0]["alg"] == "RS256" and keys[0]["use"] == "sig"
    assert keys[0]["kid"]


# ---- authorize gates ----------------------------------------------------------

def test_authorize_unknown_client(client):
    resp, _ = authorize_start(client, client_id="nope")
    assert resp.status_code == 400 and "Unknown client_id" in resp.text


def test_authorize_rejects_redirect_fuzz(client):
    resp, _ = authorize_start(client, redirect_uri="https://app.example.test/callback/extra")
    assert resp.status_code == 400 and "not registered" in resp.text


def test_authorize_requires_pkce_s256(client):
    v, c = make_pkce()
    resp = client.get("/authorize", params={
        "response_type": "code", "client_id": CLIENT_ID, "redirect_uri": REDIRECT_URI,
        "scope": "openid", "state": "s", "nonce": "n",
        "code_challenge": c, "code_challenge_method": "plain",
    })
    assert resp.status_code == 400 and "S256" in resp.text


def test_authorize_rejects_bad_scope(client):
    v, c = make_pkce()
    resp = client.get("/authorize", params={
        "response_type": "code", "client_id": CLIENT_ID, "redirect_uri": REDIRECT_URI,
        "scope": "openid admin", "state": "s", "nonce": "n",
        "code_challenge": c, "code_challenge_method": "S256",
    })
    assert resp.status_code == 400


# ---- happy path: authorize → login → token → userinfo -------------------------

def test_full_code_flow_with_php_bcrypt_hash(client):
    form, verifier = full_login(client)
    assert form.status_code == 302, form.text
    params = parse_redirect(form)
    assert params["state"] == "st-123"

    tok = client.post("/token", data={
        "grant_type": "authorization_code", "code": params["code"],
        "redirect_uri": REDIRECT_URI, "code_verifier": verifier,
    }, auth=(CLIENT_ID, CLIENT_SECRET))
    assert tok.status_code == 200, tok.text
    body = tok.json()
    assert body["token_type"] == "Bearer" and body["id_token"] and body["access_token"]

    # id_token: RS256, right claims, nonce carried through
    import jwt as pyjwt  # not a dependency; decode manually below instead

    def b64seg(s):
        p = s.split(".")
        pad = lambda x: x + "=" * (-len(x) % 4)
        return {k: __import__("json").loads(base64.urlsafe_b64decode(pad(v)).decode())
                for k, v in (("header", p[0]), ("payload", p[1]))}

    decoded = b64seg(body["id_token"])
    header, payload = decoded["header"], decoded["payload"]
    assert header["alg"] == "RS256" and header["kid"]
    assert payload["nonce"] == "n-456" and payload["email"] == "worker@dict.gov.ph"
    assert payload["aud"] == CLIENT_ID and payload["iss"] == "https://sso.dictr2.cloud"

    # userinfo via bearer
    ui = client.get("/userinfo", headers={"Authorization": f"Bearer {body['access_token']}"})
    assert ui.status_code == 200
    assert ui.json()["email"] == "worker@dict.gov.ph" and ui.json()["sub"] == payload["sub"]


def test_session_fast_path_skips_login(client):
    full_login(client)  # establishes sso_session cookie
    resp, _ = authorize_start(client, state="second", nonce="n2")
    assert resp.status_code == 302, resp.text  # no login form — straight redirect
    assert "code=" in resp.headers["location"]


# ---- token endpoint guards ------------------------------------------------------

def test_code_replay_rejected(client):
    form, verifier = full_login(client)
    params = parse_redirect(form)
    data = {"grant_type": "authorization_code", "code": params["code"],
            "redirect_uri": REDIRECT_URI, "code_verifier": verifier}
    ok = client.post("/token", data=data, auth=(CLIENT_ID, CLIENT_SECRET))
    assert ok.status_code == 200
    replay = client.post("/token", data=data, auth=(CLIENT_ID, CLIENT_SECRET))
    assert replay.status_code == 400 and replay.json()["error"] == "invalid_grant"


def test_token_rejects_wrong_pkce_verifier(client):
    form, _ = full_login(client)
    params = parse_redirect(form)
    _, other = make_pkce()
    bad = client.post("/token", data={
        "grant_type": "authorization_code", "code": params["code"],
        "redirect_uri": REDIRECT_URI, "code_verifier": other + "x",
    }, auth=(CLIENT_ID, CLIENT_SECRET))
    assert bad.status_code == 400 and "PKCE" in bad.json()["error_description"]


def test_token_rejects_wrong_client_secret(client):
    form, verifier = full_login(client)
    params = parse_redirect(form)
    bad = client.post("/token", data={
        "grant_type": "authorization_code", "code": params["code"],
        "redirect_uri": REDIRECT_URI, "code_verifier": verifier,
    }, auth=(CLIENT_ID, "wrong-secret"))
    assert bad.status_code == 401


def test_token_rejects_redirect_mismatch(client):
    form, verifier = full_login(client, redirect_uri=REDIRECT_URI + "?x=1")
    params = parse_redirect(form)
    bad = client.post("/token", data={
        "grant_type": "authorization_code", "code": params["code"],
        "redirect_uri": REDIRECT_URI, "code_verifier": verifier,
    }, auth=(CLIENT_ID, CLIENT_SECRET))
    assert bad.status_code == 400 and "redirect_uri" in bad.json()["error_description"]


# ---- login failures + lockout ----------------------------------------------------

def test_wrong_password_is_401(client):
    form, _ = full_login(client, password="nope")
    assert form.status_code == 401 and "Wrong email or password" in form.text


def test_unknown_email_generic_error(client):
    form, _ = full_login(client, email="ghost@dict.gov.ph")
    assert form.status_code == 401 and "Wrong email or password" in form.text


def test_lockout_after_threshold(client):
    for _ in range(5):
        form, _ = full_login(client, email="locked@dict.gov.ph", password="bad")
        assert form.status_code == 401
    locked = client.post("/login", data={"email": "locked@dict.gov.ph", "password": "bad"},
                         follow_redirects=False)
    assert locked.status_code == 429 and "temporarily locked" in locked.text
    # Even the CORRECT password is refused while locked (fail closed).
    still = client.post("/login", data={"email": "locked@dict.gov.ph", "password": TEST_PASSWORD},
                        follow_redirects=False)
    assert still.status_code == 429
