"""
Screenshots für die Benutzeranleitung (docs/ANLEITUNG.md) aus der Demo-Datenbank erzeugen.

Voraussetzungen (siehe docs/DEVELOPMENT.md, „Manuelle / Browser-Tests“):
  1. php bin/demo_seed.php                  (Demo-DB „haushaltsbuch_demo“)
  2. temporär public/__test.php + Umleitung in public/.htaccess (Cookie hbtest=1)
  3. python bin/screenshots.py [name ...]   (ohne Namen: alle)
Danach __test.php löschen und .htaccess zurücksetzen.
"""
import base64
import json
import os
import subprocess
import sys
import tempfile
import time
import urllib.request

import websocket

CHROME = r"C:\Program Files\Google\Chrome\Application\chrome.exe"
BASE = "http://127.0.0.1/Haushaltsbuch"
OUT = os.path.join(os.path.dirname(__file__), "..", "docs", "anleitung")
PORT = 9333

DESKTOP = (1280, 820, 1)
MOBILE = (390, 844, 2)

# Name, Pfad, Ansicht, JS vor dem Foto, max. Höhe (None = nur sichtbarer Bereich)
SHOTS = [
    ("login", "/login", MOBILE, None, None),
    ("uebersicht", "/", DESKTOP, None, 1300),
    ("uebersicht-mobil", "/", MOBILE, None, None),
    ("schnellerfassung-mobil", "/", MOBILE, "document.querySelector('[data-bs-target=\"#quickAdd\"]')?.click()", None),
    ("konten", "/accounts", DESKTOP, None, None),
    ("konto-bearbeiten", "/accounts/1/edit", DESKTOP, None, 1100),
    ("buchungen", "/transactions?period=3m", DESKTOP, None, None),
    ("buchung-neu-mobil", "/transactions/new?type=expense", MOBILE, None, None),
    ("umbuchung-mobil", "/transactions/new?type=transfer", MOBILE, None, None),
    ("fixkosten", "/recurring", DESKTOP, None, 1200),
    ("fixkosten-bearbeiten", "/recurring/13/edit", DESKTOP, None, 1300),
    ("import", "/import", DESKTOP, None, None),
    ("import-vorschau", "/import/preview", DESKTOP, None, 1200),
    ("einkaeufe", "/purchases", DESKTOP, None, None),
    ("einkauf-ansehen", "/purchases/4", DESKTOP, None, 1100),
    ("einkauf-foto-mobil", "/purchases/new?mode=photo", MOBILE, None, None),
    ("einkauf-hand", "/purchases/new?mode=manual", DESKTOP, None, 1100),
    ("auswertungen", "/reports?period=6m", DESKTOP, None, 1600),
    ("auswertung-posten", "/reports/items?period=6m&category_id=1", DESKTOP, None, 1100),
    ("prognose", "/forecast", DESKTOP, None, 1500),
    ("szenario", "/forecast/scenarios/1/edit", DESKTOP, None, None),
    ("kredit", "/loans/1", DESKTOP, None, 1400),
    ("kategorien", "/categories", DESKTOP, None, 1000),
    ("regeln", "/rules", DESKTOP, None, None),
    ("familie", "/users", DESKTOP, None, None),
    ("benutzer-rechte", "/users/2/edit", DESKTOP, None, 1100),
    ("einstellungen", "/settings", DESKTOP, None, 1200),
    ("mehr-mobil", "/more", MOBILE, None, None),
]


class Cdp:
    def __init__(self, ws_url):
        self.ws = websocket.create_connection(ws_url, timeout=60)
        self.id = 0

    def call(self, method, **params):
        self.id += 1
        self.ws.send(json.dumps({"id": self.id, "method": method, "params": params}))
        while True:
            msg = json.loads(self.ws.recv())
            if msg.get("id") == self.id:
                if "error" in msg:
                    raise RuntimeError(f"{method}: {msg['error']}")
                return msg.get("result", {})

    def eval(self, expr):
        r = self.call("Runtime.evaluate", expression=expr, returnByValue=True, awaitPromise=True)
        return r.get("result", {}).get("value")

    def goto(self, url):
        self.call("Page.navigate", url=url)
        time.sleep(0.3)
        for _ in range(100):
            if self.eval("document.readyState") == "complete":
                break
            time.sleep(0.1)
        time.sleep(1.5)  # Diagramm-Animationen, Alpine


def shoot(cdp, name, path, view, js, max_h):
    w, h, dpr = view
    cdp.call("Emulation.setDeviceMetricsOverride", width=w, height=h, deviceScaleFactor=dpr, mobile=w < 600)
    cdp.goto(BASE + path)
    if max_h:
        # Fenster auf Seitenhöhe ziehen (statt captureBeyondViewport), damit 100vh-Elemente wie die Seitenleiste mitwachsen
        full = cdp.eval("Math.max(document.documentElement.scrollHeight, document.body.scrollHeight)") or h
        cdp.call("Emulation.setDeviceMetricsOverride", width=w, height=max(h, min(full, max_h)), deviceScaleFactor=dpr, mobile=w < 600)
        time.sleep(0.5)
    cdp.eval("window.Chart && Object.values(Chart.instances).forEach(c => { c.options.animation = false; c.resize(); c.update('none') })")
    if js:
        cdp.eval(js)
        time.sleep(0.8)
    data = cdp.call("Page.captureScreenshot", format="png")["data"]
    with open(os.path.join(OUT, name + ".png"), "wb") as f:
        f.write(base64.b64decode(data))
    print("ok", name)


def main():
    only = set(sys.argv[1:])
    os.makedirs(OUT, exist_ok=True)
    profile = tempfile.mkdtemp(prefix="hb-shots-")
    proc = subprocess.Popen([CHROME, "--headless=new", f"--remote-debugging-port={PORT}", "--remote-allow-origins=*",
                             f"--user-data-dir={profile}", "--hide-scrollbars", "--lang=de-DE", "about:blank"],
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(50):
            try:
                tabs = json.load(urllib.request.urlopen(f"http://127.0.0.1:{PORT}/json"))
                page = next(t for t in tabs if t["type"] == "page")
                break
            except Exception:
                time.sleep(0.2)
        cdp = Cdp(page["webSocketDebuggerUrl"])
        cdp.call("Page.enable")
        cdp.call("Network.enable")
        cdp.call("Page.addScriptToEvaluateOnNewDocument", source="(function w(){ if (window.Chart) { Chart.defaults.animation = false } else { setTimeout(w, 5) } })()")
        cdp.call("Network.setCookie", name="hbtest", value="1", domain="127.0.0.1", path="/")
        todo = [s for s in SHOTS if not only or s[0] in only]
        if any(s[0] == "login" for s in todo):
            shoot(cdp, *next(s for s in SHOTS if s[0] == "login"))
        cdp.goto(BASE + "/?__login=1")
        if os.environ.get("HB_IMPORT_CSV"):
            # CSV hochladen, damit die Import-Vorschau etwas zeigt
            cdp.goto(BASE + "/import")
            doc = cdp.call("DOM.getDocument")
            node = cdp.call("DOM.querySelector", nodeId=doc["root"]["nodeId"], selector="input[type=file]")
            cdp.call("DOM.setFileInputFiles", nodeId=node["nodeId"], files=[os.path.abspath(os.environ["HB_IMPORT_CSV"])])
            cdp.eval("(()=>{const f=document.querySelector('input[type=file]').form;"
                     "const a=f.querySelector('select[name=account_id]'); if(a) a.value='1'; f.submit()})()")
            time.sleep(2.5)
        for s in todo:
            if s[0] != "login":
                shoot(cdp, *s)
    finally:
        proc.kill()


if __name__ == "__main__":
    main()
