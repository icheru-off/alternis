/* Alternis — client WebAuthn (clés d'accès)
   Utilise window.WA = { base, csrf } défini par la page hôte. */
(function () {
  'use strict';

  const cfg = () => (window.WA || { base: '', csrf: '' });
  function api(p) {
    const base = String(cfg().base || '').replace(/\/+$/, '');
    return (base ? base + '/' : '/') + String(p).replace(/^\/+/, '');
  }

  // base64url <-> ArrayBuffer
  function b64urlToBuf(s) {
    s = String(s).replace(/-/g, '+').replace(/_/g, '/');
    const pad = s.length % 4; if (pad) s += '='.repeat(4 - pad);
    const bin = atob(s);
    const buf = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
    return buf.buffer;
  }
  function bufToB64url(buf) {
    const bytes = new Uint8Array(buf);
    let bin = '';
    for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  async function post(path, body) {
    const r = await fetch(api(path), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': cfg().csrf },
      body: JSON.stringify(body || {})
    });
    const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(data.error || ('Erreur ' + r.status));
    return data;
  }

  window.waSupported = function () {
    return !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.create);
  };

  /* Enregistrement d'une nouvelle clé (utilisateur connecté). */
  window.waRegister = async function (label) {
    if (!window.waSupported()) throw new Error("Cet appareil ne prend pas en charge les clés d'accès.");
    const opt = await post('api/webauthn.php?action=register_begin', {});
    const pub = {
      challenge: b64urlToBuf(opt.challenge),
      rp: opt.rp,
      user: {
        id: b64urlToBuf(opt.user.id),
        name: opt.user.name,
        displayName: opt.user.displayName
      },
      pubKeyCredParams: opt.pubKeyCredParams,
      timeout: opt.timeout,
      attestation: opt.attestation,
      authenticatorSelection: opt.authenticatorSelection,
      excludeCredentials: (opt.excludeCredentials || []).map((c) => ({
        type: c.type, id: b64urlToBuf(c.id)
      }))
    };
    const cred = await navigator.credentials.create({ publicKey: pub });
    const resp = cred.response;
    const transports = (resp.getTransports && resp.getTransports()) || [];
    return await post('api/webauthn.php?action=register_finish', {
      id: cred.id,
      rawId: bufToB64url(cred.rawId),
      type: cred.type,
      label: label || '',
      transports: transports,
      response: {
        clientDataJSON: bufToB64url(resp.clientDataJSON),
        attestationObject: bufToB64url(resp.attestationObject)
      }
    });
  };

  /* Connexion via clé d'accès (page de connexion). */
  window.waLogin = async function (username) {
    if (!window.waSupported()) throw new Error("Cet appareil ne prend pas en charge les clés d'accès.");
    const opt = await post('api/webauthn.php?action=login_begin', { username: username });
    const pub = {
      challenge: b64urlToBuf(opt.challenge),
      rpId: opt.rpId,
      timeout: opt.timeout,
      userVerification: opt.userVerification,
      allowCredentials: (opt.allowCredentials || []).map((c) => ({
        type: c.type, id: b64urlToBuf(c.id), transports: c.transports || []
      }))
    };
    const assertion = await navigator.credentials.get({ publicKey: pub });
    const resp = assertion.response;
    return await post('api/webauthn.php?action=login_finish', {
      id: assertion.id,
      rawId: bufToB64url(assertion.rawId),
      type: assertion.type,
      response: {
        clientDataJSON: bufToB64url(resp.clientDataJSON),
        authenticatorData: bufToB64url(resp.authenticatorData),
        signature: bufToB64url(resp.signature),
        userHandle: resp.userHandle ? bufToB64url(resp.userHandle) : null
      }
    });
  };
})();
