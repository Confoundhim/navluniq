"""Uygulama simgesi: PIL yok, saf Python PNG (turuncu yuvarlak kare, beyaz NQ)."""
import struct, zlib, sys

SIZE = 192
BG = (234, 88, 12)     # turuncu
FG = (255, 255, 255)
R = 36
FONT = {
    'N': ["10001", "11001", "10101", "10011", "10001", "10001", "10001"],
    'Q': ["01110", "10001", "10001", "10001", "10101", "10010", "01101"],
}

def inside_round(x, y):
    cx = min(max(x, R), SIZE - 1 - R)
    cy = min(max(y, R), SIZE - 1 - R)
    return (x - cx) ** 2 + (y - cy) ** 2 <= R * R

px = [[(0, 0, 0, 0)] * SIZE for _ in range(SIZE)]
for y in range(SIZE):
    for x in range(SIZE):
        if inside_round(x, y):
            px[y][x] = BG + (255,)

scale = 14
glyph_w, glyph_h = 5 * scale, 7 * scale
total_w = 2 * glyph_w + scale
x0 = (SIZE - total_w) // 2
y0 = (SIZE - glyph_h) // 2
for gi, ch in enumerate('NQ'):
    rows = FONT[ch]
    gx = x0 + gi * (glyph_w + scale)
    for ry, row in enumerate(rows):
        for rx, bit in enumerate(row):
            if bit == '1':
                for dy in range(scale):
                    for dx in range(scale):
                        px[y0 + ry * scale + dy][gx + rx * scale + dx] = FG + (255,)

raw = b''.join(b'\x00' + bytes(sum(px[y], ())) for y in range(SIZE))

def chunk(tag, data):
    c = struct.pack('>I', len(data)) + tag + data
    return c + struct.pack('>I', zlib.crc32(tag + data) & 0xffffffff)

png = b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', SIZE, SIZE, 8, 6, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(raw, 9)) + chunk(b'IEND', b'')
open(sys.argv[1], 'wb').write(png)
