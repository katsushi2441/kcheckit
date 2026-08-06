#!/usr/bin/env python3
"""公開デモの画面を撮る。商品画像に使う。"""
from pathlib import Path
from playwright.sync_api import sync_playwright

BASE = "https://proto.exbridge.jp/kcheckit"
OUT = Path(__file__).resolve().parent.parent / "outputs"

with sync_playwright() as p:
    b = p.chromium.launch()
    pg = b.new_page(viewport={"width": 1280, "height": 900})
    pg.goto(f"{BASE}/kcheckit.php", wait_until="networkidle")
    pg.fill("#password", "demo2026")
    pg.click("button[name=login]")
    pg.wait_for_load_state("networkidle")
    pg.screenshot(path=OUT / "live-admin.png", full_page=True)
    b.close()
print("撮影完了")
