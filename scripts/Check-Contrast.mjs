import { readFileSync } from 'node:fs'

const source = readFileSync(new URL('../frontend/src/styles/_tokens.scss', import.meta.url), 'utf8')
const colors = Object.fromEntries([...source.matchAll(/^\s*([\w-]+):\s*(#[\da-f]{6}),/gim)].map((match) => [match[1], match[2]]))

function luminance(hex) {
  const channels = hex.slice(1).match(/../g).map((value) => {
    const channel = Number.parseInt(value, 16) / 255
    return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4
  })
  return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722
}

function contrast(a, b) {
  const values = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (values[0] + 0.05) / (values[1] + 0.05)
}

const checks = []
for (const background of ['background', 'surface', 'surface-raised']) {
  for (const foreground of ['text', 'muted', 'link', 'accent', 'danger']) {
    checks.push({ foreground, background, minimum: 4.5 })
  }
  for (const foreground of ['border', 'focus']) {
    checks.push({ foreground, background, minimum: 3 })
  }
}
for (const background of ['primary', 'primary-hover']) {
  checks.push({ foreground: '#ffffff', background, minimum: 4.5 })
  checks.push({ foreground: 'focus', background, minimum: 3 })
}

const results = checks.map((check) => {
  const ratio = contrast(colors[check.foreground] || check.foreground, colors[check.background])
  return { ...check, ratio: Number(ratio.toFixed(2)), pass: ratio >= check.minimum }
})
console.log(JSON.stringify({ scope: 'Opaque token pairs; not a complete WCAG audit', results }, null, 2))
if (results.some((result) => !result.pass)) process.exitCode = 1
