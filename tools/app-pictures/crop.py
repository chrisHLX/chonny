# Crop the screenshots (storage/app/app-pictures/out, 2x) to the slider's shape and write them as WebP
# into public/images/app, where <x-app-showcase> reads them. Each: (screenshot, top edge in 2x pixels).
# Run from the repo root: python tools/app-pictures/crop.py
from PIL import Image

CROPS = {
    'card': ('card', 0),
    'death': ('card', 870),   # "How it ended"
    'basics': ('basics', 280),
    'damage': ('damage', 0),
    'improve': ('improve', 0),
    'comp': ('comp', 0),
    'shuffle': ('shuffle', 0),
}
for key, (src, y) in CROPS.items():
    im = Image.open(f'storage/app/app-pictures/out/{src}.png').convert('RGB')
    im = im.crop((0, y, 1960, y + 1280)).resize((1600, 1045), Image.LANCZOS)
    im.save(f'public/images/app/{key}.webp', 'WEBP', quality=82, method=6)
    print(key)
