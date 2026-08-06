// Generates PWA PNG icons from the SpiderNetOS mark.
// One-off tool (icons are committed): npm i --no-save sharp && node scripts/generate-icons.mjs
import { mkdir, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import sharp from 'sharp'

const outDir = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'public', 'icons')

// rx: corner radius (0 = full-bleed for maskable/apple-touch), pad: glyph scale-down
const mark = ({ rx, glyphScale }) => `
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
  <rect width="512" height="512" rx="${rx}" fill="#05070A"/>
  <g transform="translate(256,256) scale(${glyphScale})" stroke="#00D6C9" stroke-width="1.4" stroke-linecap="round" opacity="0.9">
    <line x1="0" y1="0" x2="-10" y2="-10"/><line x1="0" y1="0" x2="10" y2="-10"/>
    <line x1="0" y1="0" x2="-10" y2="10"/><line x1="0" y1="0" x2="10" y2="10"/>
    <line x1="0" y1="0" x2="0" y2="-14"/><line x1="0" y1="0" x2="0" y2="14"/>
    <line x1="0" y1="0" x2="-14" y2="0"/><line x1="0" y1="0" x2="14" y2="0"/>
  </g>
  <circle cx="256" cy="256" r="${Math.round(42 * (glyphScale / 13))}" fill="#FF6B2C"/>
</svg>`

const anyIcon = Buffer.from(mark({ rx: 96, glyphScale: 13 }))
// Maskable: full-bleed background, glyph pulled into the 80% safe zone.
const maskable = Buffer.from(mark({ rx: 0, glyphScale: 11 }))

const jobs = [
  { src: anyIcon, size: 192, file: 'icon-192.png' },
  { src: anyIcon, size: 512, file: 'icon-512.png' },
  { src: maskable, size: 192, file: 'maskable-192.png' },
  { src: maskable, size: 512, file: 'maskable-512.png' },
  { src: maskable, size: 180, file: 'apple-touch-icon.png' },
]

await mkdir(outDir, { recursive: true })
for (const { src, size, file } of jobs) {
  const png = await sharp(src, { density: 300 }).resize(size, size).png().toBuffer()
  await writeFile(path.join(outDir, file), png)
  console.log(`✓ icons/${file} (${size}x${size}, ${png.length} bytes)`)
}
