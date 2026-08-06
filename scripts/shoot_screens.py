#!/usr/bin/env python3
"""ローカルで動かした kcheckit の画面を撮る。商品画像とドキュメントに使う。

前提: php -S 127.0.0.1:18304 -t public
実行: python3 scripts/shoot_screens.py
"""
from pathlib import Path
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:18304"
OUT = Path(__file__).resolve().parent.parent / "outputs"
PASSWORD = "demo2026"


def main() -> int:
    OUT.mkdir(exist_ok=True)
    with sync_playwright() as p:
        b = p.chromium.launch()
        page = b.new_page(viewport={"width": 1280, "height": 900})
        page.goto(f"{BASE}/kcheckit.php", wait_until="networkidle")
        page.screenshot(path=OUT / "screen-login.png")

        page.fill("#password", PASSWORD)
        page.click("button[name=login]")
        page.wait_for_load_state("networkidle")
        page.screenshot(path=OUT / "screen-admin.png", full_page=True)
        b.close()
    for f in sorted(OUT.glob("screen-*.png")):
        print(f"{f.name}: {f.stat().st_size:,} bytes")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
