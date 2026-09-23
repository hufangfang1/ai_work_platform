import * as T from 'three'
import { mergeGeometries } from 'three/addons/utils/BufferGeometryUtils.js'

const seeded = value => {
  let seed = value >>> 0
  return () => { seed = (seed * 1664525 + 1013904223) >>> 0; return seed / 4294967296 }
}

/**
 * A solid cement slab: X/Z centered, Y=0 at its base; +Z is its exposed edge.
 * The footprint remains exactly width × depth. Wear is only a few millimetres
 * across the walking surface; the more visible chips are confined to the lip.
 * UVs use four-metre world units. Enable vertexColors on the caller's material.
 * The caller owns and must dispose the returned BufferGeometry.
 */
export function createPorchGeometry(width = 15.3, depth = 1.8, height = .28, seed = 1979) {
  if (![width, depth, height].every(n => Number.isFinite(n) && n > 0)) throw new RangeError('Porch dimensions must be positive and finite')
  const rand = seeded(seed), segments = Math.max(2, Math.ceil(width / .13)), rows = 8
  const bevel = Math.min(.021, height * .12, depth * .12)
  const chips = Array.from({ length: Math.max(1, Math.round(width / .68)) }, () => ({
    x: (rand() - .5) * width,
    radius: Math.min(width * .24, .055 + rand() * .14),
    depth: bevel * (.25 + rand() * 1.4),
  }))
  const positions = [], uvs = [], colors = [], indices = [], sections = []
  function vertex(x, y, z, shade, uv) {
    const index = positions.length / 3
    positions.push(x, y, z); uvs.push(...uv)
    colors.push(shade, shade * .993, shade * .975)
    return index
  }
  for (let i = 0; i <= segments; i++) {
    const x = -width / 2 + i / segments * width
    let chip = 0
    for (const mark of chips) {
      const t = Math.abs(x - mark.x) / mark.radius
      if (t < 1) chip += mark.depth * (1 - t * t) ** 2
    }
    chip = Math.min(chip, height * .19, depth * .14)
    const wave = Math.sin(x * 2.31 + seed) * .0014 + Math.sin(x * 7.9) * .0007
    const wear = Math.min(height * .014, .003)
    const section = []
    section.push(vertex(x, 0, -depth / 2, .90, [x / 4, 0]))
    for (let j = 0; j <= rows; j++) {
      const t = j / rows, edge = t ** 12
      const z = -depth / 2 + t * (depth - bevel - chip * .68)
      const y = height - wear + wave * Math.sin(Math.PI * t) - chip * edge * .23
      section.push(vertex(x, y, z, .97 - edge * .035 + Math.sin(x * 3 + t) * .012, [x / 4, z / 4]))
    }
    section.push(vertex(x, height - bevel - chip, depth / 2 - chip * .17, .83 + rand() * .05, [x / 4, (height - bevel - chip) / 4]))
    section.push(vertex(x, 0, depth / 2, .77 + rand() * .04, [x / 4, 0]))
    sections.push(section)
  }
  const perimeterCount = sections[0].length
  for (let i = 0; i < segments; i++) for (let j = 0; j < perimeterCount; j++) {
    const next = (j + 1) % perimeterCount
    const a = sections[i][j], b = sections[i + 1][j], c = sections[i][next], d = sections[i + 1][next]
    indices.push(a, c, b, b, c, d)
  }
  // Duplicate the end rim so its normals stay flat, while coordinates still
  // coincide exactly with the longitudinal mesh: no light-leaking end seams.
  for (const [i, side] of [[0, -1], [segments, 1]]) {
    const x = side * width / 2, center = vertex(x, height * .45, 0, .88, [0, height * .45 / 4])
    const rim = sections[i].map(index => {
      const y = positions[index * 3 + 1], z = positions[index * 3 + 2]
      return vertex(x, y, z, .88, [z / 4, y / 4])
    })
    for (let j = 0; j < perimeterCount; j++) {
      const next = (j + 1) % perimeterCount
      indices.push(center, rim[side === 1 ? j : next], rim[side === 1 ? next : j])
    }
  }
  const geometry = new T.BufferGeometry()
  geometry.setAttribute('position', new T.Float32BufferAttribute(positions, 3))
  geometry.setAttribute('uv', new T.Float32BufferAttribute(uvs, 2))
  geometry.setAttribute('color', new T.Float32BufferAttribute(colors, 3))
  geometry.setIndex(indices)
  geometry.computeVertexNormals(); geometry.computeBoundingBox(); geometry.computeBoundingSphere()
  return geometry
}

/**
 * One merged, exposed brick course beneath the porch, centered in X/Z.
 * Brick gaps, lengths, lip wear and recesses vary without a repeating palette.
 * Use one untextured MeshStandardMaterial({ color: 'white', vertexColors: true,
 * roughness: 1 }); a full brick-wall map would put extra tiny bricks on these.
 */
export function createRiserGeometry(width = 15.3, count = 49, seed = 2015) {
  if (!Number.isFinite(width) || width <= 0 || !Number.isInteger(count) || count < 1) throw new RangeError('A riser needs positive width and an integer brick count')
  const rand = seeded(seed), gap = Math.min(.014, width / count * .07)
  const weights = Array.from({ length: count }, () => .85 + rand() * .3)
  const scale = (width - gap * (count - 1)) / weights.reduce((sum, n) => sum + n, 0)
  const parts = [], baseColors = ['#847b68', '#8c7e6c', '#796f5d', '#948370', '#807969']
  let x = -width / 2
  for (let i = 0; i < count; i++) {
    const w = weights[i] * scale, h = .175 + rand() * .04
    const piece = createPorchGeometry(w, .195 + rand() * .02, h, Math.floor(rand() * 0xffffffff))
    piece.translate(x + w / 2, .006 + rand() * .009, (rand() - .5) * .018)
    const color = new T.Color(baseColors[Math.floor(rand() * baseColors.length)])
    const attribute = piece.attributes.color, brightness = .95 + rand() * .1
    for (let j = 0; j < attribute.count; j++) attribute.setXYZ(j,
      attribute.getX(j) * color.r * brightness,
      attribute.getY(j) * color.g * brightness,
      attribute.getZ(j) * color.b * brightness)
    parts.push(piece); x += w + gap
  }
  const geometry = mergeGeometries(parts)
  for (const part of parts) part.dispose()
  geometry.computeBoundingBox(); geometry.computeBoundingSphere()
  return geometry
}
