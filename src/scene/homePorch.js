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
export function createPorchGeometry(width = 15.3, depth = 1.8, height = .28, seed = 1979, bevelLimit = .021) {
  if (![width, depth, height].every(n => Number.isFinite(n) && n > 0)) throw new RangeError('Porch dimensions must be positive and finite')
  const rand = seeded(seed), segments = Math.max(2, Math.ceil(width / .13)), rows = 8
  const bevel = Math.min(bevelLimit, height * .12, depth * .12)
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
export function createRiserGeometry(width = 15.3, count = Math.round(width / .13), seed = 2015) {
  if (!Number.isFinite(width) || width <= 0 || !Number.isInteger(count) || count < 1) throw new RangeError('A riser needs positive width and an integer brick count')
  const rand = seeded(seed), gap = Math.min(.005, width / count * .04)
  const weights = Array.from({ length: count }, () => .85 + rand() * .3)
  const scale = (width - gap * (count - 1)) / weights.reduce((sum, n) => sum + n, 0)
  const parts = [], baseColors = ['#89867c', '#918a7f', '#817f75', '#948c80', '#8a8479', '#93887c', '#87877c']
  let x = -width / 2
  for (let i = 0; i < count; i++) {
    const w = weights[i] * scale, h = .195 + rand() * .018
    const piece = createPorchGeometry(w, .195 + rand() * .02, h, Math.floor(rand() * 0xffffffff), .004)
    piece.translate(x + w / 2, .006 + rand() * .009, (rand() - .5) * .018)
    const color = new T.Color(baseColors[Math.floor(rand() * baseColors.length)])
    const attribute = piece.attributes.color, brightness = .98 + rand() * .04
    // Cement residue bridges the old brick colours; exposed clay is local,
    // rather than alternating clean red/green blocks along the whole step.
    const mortar=new T.Color('#a6a194'),position=piece.attributes.position
    for (let j = 0; j < attribute.count; j++) {
      const wash=.2+.4*Math.max(0,Math.sin(position.getX(j)*17+position.getY(j)*21+seed))
      const aged=color.clone().lerp(mortar,wash)
      attribute.setXYZ(j,attribute.getX(j)*aged.r*brightness,attribute.getY(j)*aged.g*brightness,attribute.getZ(j)*aged.b*brightness)
    }
    parts.push(piece); x += w + gap
  }
  const geometry = mergeGeometries(parts)
  for (const part of parts) part.dispose()
  geometry.computeBoundingBox(); geometry.computeBoundingSphere()
  return geometry
}

// The shallow course below the veranda is chipped and wavy, not a straight
// black drainage slot. Deform the closed slab, including both matching end rims.
export function createBrokenStepGeometry(width, depth=.38, height=.085, seed=2015){
  const geometry=createPorchGeometry(width,depth,height,seed,.018);
  const p=geometry.attributes.position,c=geometry.attributes.color;
  for(let i=0;i<p.count;i++){
    const x=p.getX(i),y=p.getY(i),z=p.getZ(i);
    const front=Math.max(0,(z+depth/2)/depth);
    const chip=Math.pow(Math.max(0,Math.sin(x*5.3+seed)*Math.cos(x*11.7)),2);
    p.setZ(i,z-front*(.012+.048*chip));
    p.setY(i,y*(.91+.09*Math.sin(x*1.7+seed))*(1-front*.28*chip));
    const shade=1-front*(.04+.12*chip);
    c.setXYZ(i,c.getX(i)*shade,c.getY(i)*shade,c.getZ(i)*shade);
  }
  geometry.computeVertexNormals();geometry.computeBoundingBox();geometry.computeBoundingSphere();return geometry;
}

// Solid wedge, high at -Z against the porch, feathering to a hair under the
// courtyard plane at +Z so the cement reads as one continuous surface with
// the yard, never a raised lip. Reuse the cement slab's worn edge and
// physical UVs, not a rotated box with an exposed vertical foot. The rear
// reaches the same slab height exactly.
export function createPorchRampGeometry(width,run,height,seed=2015){
  const geometry=createPorchGeometry(width,run,height,seed,.009);
  const p=geometry.attributes.position;
  for(let i=0;i<p.count;i++){
    const t=T.MathUtils.clamp((p.getZ(i)+run/2)/run,0,1);
    const top=height*(1-t)+.0008*t;
    p.setY(i,p.getY(i)/height*top);
  }
  geometry.computeVertexNormals();geometry.computeBoundingBox();geometry.computeBoundingSphere();return geometry;
}
