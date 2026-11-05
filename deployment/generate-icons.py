"""Generate deterministic PNG icons with the Python standard library; no packages needed."""
from pathlib import Path
import struct
import zlib

ROOT = Path(__file__).resolve().parents[1] / 'public' / 'icons'


def chunk(kind, payload):
    return struct.pack('!I', len(payload)) + kind + payload + struct.pack('!I', zlib.crc32(kind + payload) & 0xffffffff)


def icon(size):
    # Geometric LG mark, matching the SVG palette. Coordinates use a 512-unit grid.
    strokes = [(130, 158, 158, 354), (130, 326, 238, 354),
               (266, 170, 382, 198), (266, 170, 294, 342),
               (266, 314, 382, 342), (354, 250, 382, 342),
               (322, 250, 382, 278)]
    raw = bytearray()
    for y in range(size):
        raw.append(0)
        for x in range(size):
            sx, sy = (x + 0.5) * 512 / size, (y + 0.5) * 512 / size
            color = (11, 16, 32)
            if 56 <= sx < 456 and 56 <= sy < 456:
                color = (128, 228, 192)
            if any(x0 <= sx < x1 and y0 <= sy < y1 for x0, y0, x1, y1 in strokes):
                color = (17, 40, 32)
            raw.extend(color)
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('!2I5B', size, size, 8, 2, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress(bytes(raw), 9)) + chunk(b'IEND', b''))


if __name__ == '__main__':
    ROOT.mkdir(parents=True, exist_ok=True)
    for size in (192, 512):
        destination = ROOT / f'icon-{size}.png'
        destination.write_bytes(icon(size))
        print(f'Generated {destination.name} ({size}x{size})')
