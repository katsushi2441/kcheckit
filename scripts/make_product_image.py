#!/usr/bin/env python3
"""kappstore 出品用の商品画像(1200x750 / 16:10)。

一覧はカード表示で aspect-ratio 16/10。実画面のスクリーンショットを使い、
「何ができるか」が一目で分かる形にする。

前提: python3 scripts/shoot_live.py で outputs/live-admin.png を撮っておく
実行: python3 scripts/make_product_image.py
"""
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

FONT = "/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc"
FONT_R = "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc"

W, H = 1200, 750
FOAM, PANEL, ABYSS, MUTED = "#f5fbfb", "#e7f3f2", "#12202f", "#55697a"
TEAL, TEAL_DEEP = "#12a99f", "#0a726b"

ROOT = Path(__file__).resolve().parent.parent
SHOT = ROOT / "outputs" / "live-admin.png"
OUT = ROOT / "outputs" / "kcheckit-product.png"

img = Image.new("RGB", (W, H), FOAM)
dr = ImageDraw.Draw(img)
dr.rectangle([(0, 0), (W, 8)], fill=TEAL)

f_eyebrow = ImageFont.truetype(FONT, 21)
f_title = ImageFont.truetype(FONT, 46)
f_lead = ImageFont.truetype(FONT_R, 23)
f_step = ImageFont.truetype(FONT, 25)
f_note = ImageFont.truetype(FONT_R, 19)

x = 62
dr.text((x, 52), "KURAGE CHECK IT FRAMEWORK", font=f_eyebrow, fill=TEAL_DEEP)
dr.text((x, 92), "チェックして、保存して、知らせる", font=f_title, fill=ABYSS)
dr.text((x, 162), "自社サイトのURLと、見ておきたいサイトのURLを登録するだけ。", font=f_lead, fill=MUTED)
dr.text((x, 196), "新着から自社に関係するものだけを、理由つきでお知らせします。", font=f_lead, fill=MUTED)

# 4段階の骨格。この製品の中身そのものなので、図として見せる
steps = [("CHECK", "取りに行く"), ("JUDGE", "関係あるか"),
         ("STORE", "詳細を保存"), ("NOTIFY", "要点を通知")]
bx, by, bw, bh, gap = x, 248, 232, 84, 16
for i, (en, ja) in enumerate(steps):
    left = bx + i * (bw + gap)
    dr.rounded_rectangle([(left, by), (left + bw, by + bh)], radius=14,
                         fill=PANEL, outline="#cde5e2", width=2)
    dr.text((left + 20, by + 16), en, font=f_step, fill=TEAL_DEEP)
    dr.text((left + 20, by + 50), ja, font=f_note, fill=MUTED)
    if i < 3:
        ar = left + bw + 3
        dr.text((ar, by + 28), "›", font=f_step, fill=TEAL)

# 実画面。上部だけを切り出して「本当に動くもの」を見せる
if SHOT.exists():
    shot = Image.open(SHOT).convert("RGB")
    crop_h = min(shot.height, int(shot.width * 0.42))
    shot = shot.crop((0, 0, shot.width, crop_h))
    tw = W - x * 2
    shot = shot.resize((tw, int(shot.height * tw / shot.width)), Image.LANCZOS)
    max_h = 232
    if shot.height > max_h:
        shot = shot.crop((0, 0, shot.width, max_h))
    sy = 372
    dr.rounded_rectangle([(x - 4, sy - 4), (x + shot.width + 4, sy + shot.height + 4)],
                         radius=12, fill="#cde5e2")
    img.paste(shot, (x, sy))

# 下段：この製品を選ぶ理由
notes = [
    "RSS / API / HTMLの差分 / SSL期限 の4方式",
    "セキュリティ・補助金・法令の監視が最初から入っています",
    "データベース不要。FTPで上げるだけ。cronが無くてもボタンで動きます",
]
ny = 636
for i, t in enumerate(notes):
    dr.ellipse([(x, ny + i * 33 + 7), (x + 9, ny + i * 33 + 16)], fill=TEAL)
    dr.text((x + 22, ny + i * 33), t, font=f_note, fill=ABYSS)

dr.text((W - 62 - dr.textlength("MIT License", font=f_note), ny + 66),
        "MIT License", font=f_note, fill=MUTED)

OUT.parent.mkdir(exist_ok=True)
img.save(OUT, quality=92)
print(f"できました: {OUT} ({OUT.stat().st_size:,} bytes)")
