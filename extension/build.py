#!/usr/bin/env python3
"""
Construit les paquets de l'extension Alternis à partir de src/.

  python3 extension/build.py

Produit dans extension/dist/ :
  - alternis-extension-firefox.zip  (background.scripts + réglages gecko)
  - alternis-extension-chrome.zip   (background.service_worker, sans clés gecko)
    → Chrome, Edge, Opera, Brave
"""
import json
import zipfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
SRC = HERE / "src"
DIST = HERE / "dist"


def files():
    for p in sorted(SRC.rglob("*")):
        if p.is_file() and p.name != "manifest.json" and not p.name.startswith("."):
            yield p


def build(target: str, manifest: dict) -> Path:
    m = json.loads(json.dumps(manifest))
    if target == "firefox":
        m["background"] = {"scripts": ["background.js"]}
    else:
        m["background"] = {"service_worker": "background.js"}
        m.pop("browser_specific_settings", None)
    out = DIST / f"alternis-extension-{target}.zip"
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("manifest.json", json.dumps(m, ensure_ascii=False, indent=2) + "\n")
        for p in files():
            z.write(p, p.relative_to(SRC).as_posix())
    return out


def main():
    DIST.mkdir(exist_ok=True)
    manifest = json.loads((SRC / "manifest.json").read_text(encoding="utf-8"))
    for t in ("firefox", "chrome"):
        print("✓", build(t, manifest).relative_to(HERE.parent), f"(v{manifest['version']})")


if __name__ == "__main__":
    main()
