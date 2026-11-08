"""Render the WordPress.org assets from the HTML sources. Run: python render.py"""
import os
from playwright.sync_api import sync_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.dirname(HERE)
JOBS = [
    # file, selector, width, height, scale, output
    ("banner.html",   ".banner", 1544, 500, 1, "banner-1544x500.png"),
    ("banner.html",   ".banner", 1544, 500, 0.5, "banner-772x250.png"),
    ("icon.html",     ".icon",    256, 256, 1, "icon-256x256.png"),
    ("icon.html",     ".icon",    256, 256, 0.5, "icon-128x128.png"),
    ("diagram.html",  ".d",      1600, 900, 1, "how-it-works-1600x900.png"),
    ("social.html",   ".s",      1200, 630, 1, "social-1200x630.png"),
    ("terminal.html", ".wrap",   1280, 800, 1, "screenshot-7.png"),
]

with sync_playwright() as p:
    b = p.chromium.launch()
    for src, sel, w, h, scale, out in JOBS:
        ctx = b.new_context(viewport={"width": w, "height": h}, device_scale_factor=scale)
        page = ctx.new_page()
        page.goto("file:///" + os.path.join(HERE, src).replace("\\", "/"), wait_until="networkidle")
        page.wait_for_timeout(400)  # web fonts
        page.locator(sel).screenshot(path=os.path.join(OUT, out), omit_background=src == "icon.html")
        ctx.close()
        print("wrote", out)
    b.close()
