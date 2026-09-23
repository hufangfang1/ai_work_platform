import * as T from 'three'

const seeded = value => {
  let state = value >>> 0
  return () => { state = (Math.imul(state, 1664525) + 1013904223) >>> 0; return state / 4294967296 }
}

function validate(width, height, depth, seed) {
  if (![width, height, depth].every(n => Number.isFinite(n) && n > 0)) throw new RangeError('Timber dimensions must be positive and finite')
  if (!Number.isFinite(seed)) throw new RangeError('Timber seed must be finite')
}

function surfaceBuilder() {
  const positions = [], uvs = [], colors = [], indices = []
  function surface(points, textureCoordinates, shades, outward) {
    const offset = positions.length / 3
    for (let i = 0; i < points.length; i++) {
      positions.push(...points[i]); uvs.push(...textureCoordinates[i])
      const shade = shades[i]
      colors.push(shade, shade * .998, shade * .992)
    }
    const edgeA = new T.Vector3().fromArray(points[1]).sub(new T.Vector3().fromArray(points[0]))
    const edgeB = new T.Vector3().fromArray(points[2]).sub(new T.Vector3().fromArray(points[0]))
    const reverse = edgeA.cross(edgeB).dot(new T.Vector3().fromArray(outward)) < 0
    for (let i = 1; i < points.length - 1; i++) indices.push(offset, offset + (reverse ? i + 1 : i), offset + (reverse ? i : i + 1))
  }
  function finish() {
    const geometry = new T.BufferGeometry()
    geometry.setAttribute('position', new T.Float32BufferAttribute(positions, 3))
    geometry.setAttribute('uv', new T.Float32BufferAttribute(uvs, 2))
    geometry.setAttribute('color', new T.Float32BufferAttribute(colors, 3))
    geometry.setIndex(indices)
    geometry.computeVertexNormals(); geometry.computeBoundingBox(); geometry.computeBoundingSphere()
    return geometry
  }
  return { surface, finish }
}

/**
 * A centered, solid square-section timber, not a rounded capsule. Its broad
 * faces remain planar; only the tiny corner facets change along its length.
 * All wear is inside the original width/height/depth. Grain always follows V:
 * one texture repeat is 1 m across the grain and 3 m along it. Use an unscaled
 * repeating wood map with a white material and vertexColors enabled.
 * The caller owns the returned geometry; this function owns no GPU textures.
 */
export function createTimberGeometry(width, height, depth, { seed = 1, grainAxis = 'y' } = {}) {
  validate(width, height, depth, seed)
  if (!['x', 'y'].includes(grainAxis)) throw new RangeError('Timber grainAxis must be x or y')
  const rand = seeded(seed), long = grainAxis === 'y' ? height : width, across = grainAxis === 'y' ? width : height
  const bevel = Math.min(.0042 + rand() * .001, Math.min(across, long, depth) * .18)
  const segments = Math.max(2, Math.min(20, Math.ceil(long / .22)))
  const offsetU = rand(), offsetV = rand(), { surface, finish } = surfaceBuilder()
  const longitudinal = [-long / 2, -long / 2 + bevel]
  for (let i = 1; i < segments; i++) longitudinal.push(-long / 2 + bevel + (long - bevel * 2) * i / segments)
  longitudinal.push(long / 2 - bevel, long / 2)
  const convert = (a, b, z) => grainAxis === 'y' ? [a, b, z] : [b, a, z]
  const rings = longitudinal.map((b, index) => {
    const inset = index === 0 || index === longitudinal.length - 1 ? bevel : 0
    const halfAcross = across / 2 - inset, halfDepth = depth / 2 - inset
    const cuts = Array.from({ length: 4 }, () => Math.min(bevel * (.7 + rand() * .42), halfAcross * .55, halfDepth * .55))
    const cross = [
      [-halfAcross + cuts[0], halfDepth], [halfAcross - cuts[1], halfDepth],
      [halfAcross, halfDepth - cuts[1]], [halfAcross, -halfDepth + cuts[2]],
      [halfAcross - cuts[2], -halfDepth], [-halfAcross + cuts[3], -halfDepth],
      [-halfAcross, -halfDepth + cuts[3]], [-halfAcross, halfDepth - cuts[0]],
    ]
    return { b, cross, points: cross.map(([a, z]) => convert(a, b, z)) }
  })
  function color(b, edge) {
    // A little pale wear on the bevel, plus a narrow dusty lower end; never
    // dark polygonal blotches baked into a large flat face.
    const dust = grainAxis === 'y' ? Math.exp(-(b + long / 2) / Math.min(.18, long * .15)) * .032 : 0
    return Math.min(.998, .966 + (edge ? .019 : 0) - dust)
  }
  for (let row = 0; row < rings.length - 1; row++) {
    const a = rings[row], b = rings[row + 1]
    for (let side = 0; side < 8; side++) {
      const next = (side + 1) % 8, edge = side % 2 === 1
      const reference = [a.cross[side], a.cross[next], b.cross[next], b.cross[side]]
      const lengthCoordinates = [a.b, a.b, b.b, b.b]
      const points = [a.points[side], a.points[next], b.points[next], b.points[side]]
      const acrossIsX = side === 0 || side === 4 || edge
      const uv = reference.map(([x, z], i) => [offsetU + (acrossIsX ? x : z), offsetV + lengthCoordinates[i] / 3])
      const out = convert((reference[0][0] + reference[1][0]) / 2, 0, (reference[0][1] + reference[1][1]) / 2)
      surface(points, uv, lengthCoordinates.map(value => color(value, edge || row === 0 || row === rings.length - 2)), out)
    }
  }
  for (const [index, sign] of [[0, -1], [rings.length - 1, 1]]) {
    const ring = rings[index], center = convert(0, ring.b, 0)
    for (let side = 0; side < 8; side++) {
      const next = (side + 1) % 8
      surface([center, ring.points[side], ring.points[next]],
        [[offsetU, offsetV], ...[side, next].map(i => [offsetU + ring.cross[i][0], offsetV + ring.cross[i][1] / 3])],
        [color(ring.b, false), color(ring.b, true), color(ring.b, true)], convert(0, sign, 0))
    }
  }
  const geometry = finish()
  geometry.userData = { grainAxis, bevel, textureUnit: [1, 3] }
  return geometry
}

/**
 * Plain village plank door. A single closed solid with shallow 3 mm V seams,
 * rather than holes between disconnected boards or decorative inset panels.
 * +Z is the front. All four exterior edges retain the supplied dimensions.
 * Physical UV units and disposal ownership match createTimberGeometry.
 */
export function createWoodenDoorGeometry(width = 1.28, height = 2.65, depth = .075, { seed = 2015 } = {}) {
  validate(width, height, depth, seed)
  const rand = seeded(seed), { surface, finish } = surfaceBuilder()
  const boardCount = Math.max(2, Math.min(10, Math.round(width / .235)))
  const weights = Array.from({ length: boardCount }, () => .9 + rand() * .2)
  const total = weights.reduce((sum, value) => sum + value, 0)
  const bevel = Math.min(.0025, Math.min(width, height, depth) * .08)
  const seamWidth = Math.min(.003, width / boardCount * .05), seamDepth = Math.min(.0014, depth * .05)
  const offsetU = rand(), offsetV = rand(), shade = Array.from({ length: boardCount }, () => .957 + rand() * .025)
  const profile = [{ x: -width / 2, dip: bevel, board: 0 }, { x: -width / 2 + bevel, dip: 0, board: 0 }]
  let x = -width / 2
  for (let i = 0; i < boardCount - 1; i++) {
    x += width * weights[i] / total
    profile.push({ x: x - seamWidth / 2, dip: 0, board: i }, { x, dip: seamDepth, board: i }, { x: x + seamWidth / 2, dip: 0, board: i + 1 })
  }
  profile.push({ x: width / 2 - bevel, dip: 0, board: boardCount - 1 }, { x: width / 2, dip: bevel, board: boardCount - 1 })
  const levels = [-height / 2, -height / 2 + bevel]
  for (let i = 1; i < 10; i++) levels.push(-height / 2 + bevel + (height - bevel * 2) * i / 10)
  levels.push(height / 2 - bevel, height / 2)
  const front = (column, row) => {
    const section = profile[column], edge = row === 0 || row === levels.length - 1
    return [section.x, levels[row], depth / 2 - (edge ? Math.max(bevel, section.dip) : section.dip)]
  }
  const uv = ([px, py]) => [offsetU + px, offsetV + py / 3]
  const tint = (column, row) => {
    const dust = .024 * Math.exp(-(levels[row] + height / 2) / Math.min(.24, height * .15))
    const edge = column === 0 || column === profile.length - 1 || row === 0 || row === levels.length - 1
    return Math.min(.998, shade[profile[column].board] + (edge ? .012 : 0) - dust)
  }
  for (let col = 0; col < profile.length - 1; col++) for (let row = 0; row < levels.length - 1; row++) {
    const corners = [[col, row], [col + 1, row], [col + 1, row + 1], [col, row + 1]]
    const points = corners.map(([c, r]) => front(c, r))
    surface(points, points.map(uv), corners.map(([c, r]) => tint(c, r)), [0, 0, 1])
  }
  // Matching cap subdivisions keep every front edge connected to a real
  // thickness, including the tiny grooves at the top and bottom of the door.
  for (const [row, sign] of [[0, -1], [levels.length - 1, 1]]) {
    for (let col = 0; col < profile.length - 1; col++) {
      const a = front(col, row), b = front(col + 1, row)
      const points = [a, b, [b[0], b[1], -depth / 2], [a[0], a[1], -depth / 2]]
      surface(points, points.map(([px, , pz]) => [offsetU + px, offsetV + pz / 3]), [.97, .97, .952, .952], [0, sign, 0])
    }
  }
  for (const [col, sign] of [[0, -1], [profile.length - 1, 1]]) {
    for (let row = 0; row < levels.length - 1; row++) {
      const a = front(col, row), b = front(col, row + 1)
      const points = [a, b, [b[0], b[1], -depth / 2], [a[0], a[1], -depth / 2]]
      surface(points, points.map(([, py, pz]) => [offsetU + pz, offsetV + py / 3]), [.97, .97, .952, .952], [sign, 0, 0])
    }
  }
  // A subdivided flat rear surface, so its boundary vertices match the caps
  // exactly instead of creating T junctions at each plank seam.
  for (let col = 0; col < profile.length - 1; col++) for (let row = 0; row < levels.length - 1; row++) {
    const points = [[profile[col].x, levels[row], -depth / 2], [profile[col + 1].x, levels[row], -depth / 2],
      [profile[col + 1].x, levels[row + 1], -depth / 2], [profile[col].x, levels[row + 1], -depth / 2]]
    surface(points, points.map(uv), [.959, .959, .959, .959], [0, 0, -1])
  }
  const geometry = finish()
  geometry.userData = { grainAxis: 'y', bevel, seamWidth, seamDepth, boardCount, textureUnit: [1, 3] }
  return geometry
}
