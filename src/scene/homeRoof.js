import * as T from 'three'

const seeded = seed => {
  let value = seed >>> 0
  return () => { value = (Math.imul(value, 1664525) + 1013904223) >>> 0; return value / 4294967296 }
}
const positive = values => values.every(value => Number.isFinite(value) && value > 0)
const curvature = (kind, width) => width * (kind === 'pan' ? .11 : .27)

/**
 * A small solid clay tile. X is across the roof, Z runs downhill and +Z is
 * the visible tile mouth. Pan tiles are shallow troughs; narrow cap tiles
 * arch over the seam between two pans. UVs are physical metres, not one
 * whole texture per tile. The caller owns/disposes the returned geometry.
 */
export function createRoofTileGeometry({ kind = 'pan', width = kind === 'pan' ? .24 : .1152, length = .42, thickness = .012, seed = 2015 } = {}) {
  if (!['pan', 'cap'].includes(kind)) throw new RangeError('Roof tile kind must be pan or cap')
  if (!positive([width, length, thickness]) || thickness >= Math.min(width, length) / 3 || !Number.isFinite(seed)) throw new RangeError('Roof tile dimensions and seed must be finite, with a thin positive thickness')
  const rand = seeded(seed), segments = 12, height = curvature(kind, width)
  const offsetU = rand() * 3, offsetV = rand() * 3, twist = (rand() - .5) * .0012
  const positions = [], uvs = [], colors = [], indices = []
  const profile = (x, z) => {
    const u = x / (width / 2), v = z / (length / 2)
    // Millimetre-scale firing irregularity dies off at the bearing edges.
    return height * (kind === 'pan' ? u * u : 1 - u * u) + twist * u * v * (1 - u * u)
  }
  const vertex = (point, uv, shade) => {
    const index = positions.length / 3
    positions.push(...point); uvs.push(...uv)
    colors.push(shade, shade * .998, shade * .991)
    return index
  }
  const quad = (a, b, c, d, reverse = false) => {
    indices.push(a, reverse ? c : b, reverse ? b : c, a, reverse ? d : c, reverse ? c : d)
  }
  // Shared vertices only across broad curved surfaces: the clay mouth and
  // side edges keep hard normals instead of shading like a soft rubber tube.
  const rings = []
  for (const layer of [0, 1]) {
    const rows = []
    for (const z of [-length / 2, length / 2]) {
      const row = []
      for (let i = 0; i <= segments; i++) {
        const x = -width / 2 + width * i / segments, edge = Math.abs(x / (width / 2))
        const dust = z > 0 ? .018 : 0, trough = kind === 'pan' ? (1 - edge) * .025 : 0
        row.push(vertex([x, profile(x, z) - layer * thickness, z], [offsetU + x, offsetV + z], .965 + dust - trough - layer * .035))
      }
      rows.push(row)
    }
    for (let i = 0; i < segments; i++) quad(rows[0][i], rows[0][i + 1], rows[1][i + 1], rows[1][i], layer === 0)
    rings.push(rows)
  }
  const readPoint = index => positions.slice(index * 3, index * 3 + 3)
  const capQuad = (points, out, textureAxis) => {
    const ids = points.map(point => vertex(point, [offsetU + point[textureAxis[0]], offsetV + point[textureAxis[1]]], .972))
    const a = new T.Vector3(...points[0]), b = new T.Vector3(...points[1]), c = new T.Vector3(...points[2])
    const reverse = b.sub(a).cross(c.sub(a)).dot(new T.Vector3(...out)) < 0
    quad(...ids, reverse)
  }
  for (const [row, sign] of [[0, -1], [1, 1]]) for (let i = 0; i < segments; i++) {
    capQuad([rings[0][row][i], rings[0][row][i + 1], rings[1][row][i + 1], rings[1][row][i]].map(readPoint), [0, 0, sign], [0, 1])
  }
  for (const [column, sign] of [[0, -1], [segments, 1]]) {
    capQuad([rings[0][0][column], rings[0][1][column], rings[1][1][column], rings[1][0][column]].map(readPoint), [sign, 0, 0], [1, 2])
  }
  const geometry = new T.BufferGeometry()
  geometry.setAttribute('position', new T.Float32BufferAttribute(positions, 3))
  geometry.setAttribute('uv', new T.Float32BufferAttribute(uvs, 2))
  geometry.setAttribute('color', new T.Float32BufferAttribute(colors, 3))
  geometry.setIndex(indices)
  geometry.computeVertexNormals(); geometry.computeBoundingBox(); geometry.computeBoundingSphere()
  geometry.userData = { kind, width, length, thickness, height, segments, textureUnit: [1, 1] }
  return geometry
}

/**
 * A bounded, overlapping single-slope roof, relative to the existing roof's
 * centre. Each item is ready for an InstancedMesh Object3D transform. Group
 * by kind + variant: only six geometries are needed for any roof size.
 *
 * Depth and Z are horizontal footprint distances, so tile centres follow
 * y = -tan(tilt) * z (not a sine approximation of the slope). A tiny
 * shallower tile pitch lifts
 * each upstream tile mouth over the next tile by one real clay thickness;
 * merely putting every tile at the roof pitch would interpenetrate at laps.
 */
export function createRoofTileLayout(width, depth, { tilt = 0, spacing = .24, rowStep = .31, seed = 2015 } = {}) {
  if (!positive([width, depth, spacing, rowStep]) || !Number.isFinite(tilt) || Math.abs(tilt) > .6 || !Number.isFinite(seed)) throw new RangeError('Roof dimensions must be positive and finite, with a moderate single slope')
  const columns = Math.max(1, Math.round(width / spacing)), panWidth = width / columns
  const capWidth = panWidth * .48, thickness = Math.min(.012, panWidth * .12, depth * .035)
  const length = Math.min(.42, depth * .9)
  // A caller may request a wide row spacing; never turn that into open
  // channels in the covering. Keep at least a fifth-tile overlap.
  const rows = Math.max(1, Math.ceil((depth - length) / Math.min(rowStep, length * .8)) + 1)
  if (rows * columns > 30000) throw new RangeError('Roof tile layout is too large')
  const panHeight = curvature('pan', panWidth), capHeight = curvature('cap', capWidth)
  const capRaise = thickness + panHeight * (1 - capWidth / panWidth) ** 2
  const maxY = Math.max(panHeight, capHeight, thickness) + .001
  let step = rows > 1 ? (depth - length) / (rows - 1) : 0, pitch = tilt, halfExtent = length / 2
  for (let i = 0; i < 8; i++) {
    pitch = rows > 1 ? Math.atan(Math.tan(tilt) - (thickness + .0014) / step) : tilt
    halfExtent = length / 2 * Math.abs(Math.cos(pitch)) + maxY * Math.abs(Math.sin(pitch))
    step = rows > 1 ? (depth - 2 * halfExtent) / (rows - 1) : 0
  }
  const pans = [], caps = [], rand = seeded(seed)
  const item = (kind, column, row, x, z, tileWidth, rise) => {
    // Colour is already linear RGB. A material should remain neutral; these
    // values vary subtly rather than painting individual stripes on the roof.
    const weather = Math.sin(x*.73+seed*.01)*Math.sin(z*.91+.4);
    const tint = .92 + rand() * .045 + weather*.018 - (row/(Math.max(1,rows-1)))*.012
    return {
      kind, column, row, variant: Math.floor(rand() * 3), width: tileWidth, length, thickness,
      position: [x, -Math.tan(tilt) * z + thickness + rise, z],
      rotation: [pitch, 0, 0], color: [tint, tint * .995, tint * .978],
    }
  }
  for (let row = 0; row < rows; row++) {
    const z = rows === 1 ? 0 : -depth / 2 + halfExtent + row * step
    for (let column = 0; column < columns; column++) pans.push(item('pan', column, row, -width / 2 + (column + .5) * panWidth, z, panWidth, 0))
    for (let column = 0; column < columns - 1; column++) caps.push(item('cap', column, row, -width / 2 + (column + 1) * panWidth, z, capWidth, capRaise))
  }
  return { pans, caps, columns, rows, rowStep: step, pitch, thickness, panWidth, capWidth, length }
}
