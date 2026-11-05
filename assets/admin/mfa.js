/* Yii3 Enterprise Platform — two-factor helpers (passkeys / WebAuthn, E-IMZO). No inline JS (strict CSP).
 * Author: ShokirjonMK */
(function () {
  'use strict';

  // ---------------------------------------------------------------- base64url <-> ArrayBuffer
  function b64uToBuf(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) { s += '='; }
    var bin = atob(s), out = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) { out[i] = bin.charCodeAt(i); }
    return out.buffer;
  }
  function bufToB64u(buf) {
    var bytes = new Uint8Array(buf), bin = '';
    for (var i = 0; i < bytes.length; i++) { bin += String.fromCharCode(bytes[i]); }
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function csrfOf(form) {
    var el = form.querySelector('input[name="_csrf"]');
    return el ? el.value : '';
  }
  function post(url, form) {
    var body = new URLSearchParams();
    body.append('_csrf', csrfOf(form));
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' }, body: body })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw new Error(j.error || ('HTTP ' + r.status)); } return j; }); });
  }
  function status(form, sel, text) {
    var el = form.querySelector(sel);
    if (el) { el.textContent = text; }
  }

  // ---------------------------------------------------------------- passkeys
  document.addEventListener('submit', function (e) {
    var form = e.target.closest('form[data-webauthn]');
    if (!form || form.dataset.ready === '1') { return; }
    e.preventDefault();
    if (!window.PublicKeyCredential || !navigator.credentials) {
      status(form, '[data-webauthn-status]', 'This browser does not support passkeys.');
      return;
    }
    var mode = form.dataset.webauthn;
    status(form, '[data-webauthn-status]', '…');
    post(form.dataset.optionsUrl, form).then(function (o) {
      o.challenge = b64uToBuf(o.challenge);
      if (mode === 'create') {
        o.user.id = b64uToBuf(o.user.id);
        (o.excludeCredentials || []).forEach(function (c) { c.id = b64uToBuf(c.id); });
        return navigator.credentials.create({ publicKey: o });
      }
      (o.allowCredentials || []).forEach(function (c) { c.id = b64uToBuf(c.id); });
      return navigator.credentials.get({ publicKey: o });
    }).then(function (cred) {
      var r = cred.response, json = { id: cred.id, rawId: bufToB64u(cred.rawId), type: cred.type, response: { clientDataJSON: bufToB64u(r.clientDataJSON) } };
      if (mode === 'create') {
        json.response.attestationObject = bufToB64u(r.attestationObject);
        json.response.transports = typeof r.getTransports === 'function' ? r.getTransports() : [];
      } else {
        json.response.authenticatorData = bufToB64u(r.authenticatorData);
        json.response.signature = bufToB64u(r.signature);
        json.response.userHandle = r.userHandle ? bufToB64u(r.userHandle) : null;
      }
      form.querySelector('input[name="credential"]').value = JSON.stringify(json);
      form.dataset.ready = '1';
      form.submit();
    }).catch(function (err) {
      status(form, '[data-webauthn-status]', (err && err.name === 'NotAllowedError') ? 'Cancelled or timed out. Try again.' : String(err && err.message || err));
    });
  });

  // ---------------------------------------------------------------- E-IMZO (local desktop app, websocket API)
  function eimzoCall(payload) {
    return new Promise(function (resolve, reject) {
      var url = location.protocol === 'https:' ? 'wss://127.0.0.1:64443/service/cryptapi' : 'ws://127.0.0.1:64646/service/cryptapi';
      var ws;
      try { ws = new WebSocket(url); } catch (e) { reject(e); return; }
      var done = false;
      ws.onopen = function () { ws.send(JSON.stringify(payload)); };
      ws.onmessage = function (ev) {
        done = true; ws.close();
        var data; try { data = JSON.parse(ev.data); } catch (e) { reject(e); return; }
        if (data.success === false) { reject(new Error(data.reason || 'E-IMZO error')); } else { resolve(data); }
      };
      ws.onerror = function () { if (!done) { reject(new Error('E-IMZO is not running on this computer (install/start it from e-imzo.uz).')); } };
    });
  }
  function aliasField(alias, key) {
    var m = new RegExp('(?:^|,)' + key + '=([^,]*)', 'i').exec(alias || '');
    return m ? m[1] : '';
  }

  function initEimzo(form) {
    if (form.dataset.eimzoInit === '1') { return; }
    form.dataset.eimzoInit = '1';
    var select = form.querySelector('[data-eimzo-keys]'), sign = form.querySelector('[data-eimzo-sign]');
    var challenge = null, certs = [];
    post(form.dataset.challengeUrl, form).then(function (c) {
      challenge = c.challenge;
      return eimzoCall({ name: 'apikey', arguments: c.apiKeys || [] });
    }).then(function () {
      return eimzoCall({ plugin: 'pfx', name: 'list_all_certificates' });
    }).then(function (r) {
      certs = r.certificates || [];
      select.innerHTML = '';
      if (!certs.length) {
        select.appendChild(new Option('No E-IMZO keys found', ''));
        return;
      }
      certs.forEach(function (c, i) {
        var cn = aliasField(c.alias, 'cn'), tin = aliasField(c.alias, 'uid') || aliasField(c.alias, '1.2.860.3.16.1.1');
        var to = aliasField(c.alias, 'validto');
        select.appendChild(new Option((cn || c.name) + (tin ? ' · ' + tin : '') + (to ? ' · ' + to.substring(0, 10) : ''), String(i)));
      });
      select.disabled = false; sign.disabled = false;
      status(form, '[data-eimzo-status]', '');
    }).catch(function (err) {
      status(form, '[data-eimzo-status]', String(err && err.message || err));
    });

    form.addEventListener('submit', function (e) {
      if (form.dataset.ready === '1') { return; }
      e.preventDefault();
      var c = certs[parseInt(select.value, 10)];
      if (!c || !challenge) { return; }
      sign.disabled = true;
      status(form, '[data-eimzo-status]', 'Enter the key password in the E-IMZO window…');
      eimzoCall({ plugin: 'pfx', name: 'load_key', arguments: [c.disk, c.path, c.name, c.alias] }).then(function (k) {
        return eimzoCall({ plugin: 'pkcs7', name: 'create_pkcs7', arguments: [btoa(unescape(encodeURIComponent(challenge))), k.keyId, 'no'] });
      }).then(function (p) {
        form.querySelector('input[name="pkcs7"]').value = p.pkcs7_64;
        form.dataset.ready = '1';
        form.submit();
      }).catch(function (err) {
        sign.disabled = false;
        status(form, '[data-eimzo-status]', String(err && err.message || err));
      });
    });
  }

  function scan() { document.querySelectorAll('form[data-eimzo]').forEach(initEimzo); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', scan); } else { scan(); }
  document.addEventListener('htmx:afterSwap', scan);
})();
