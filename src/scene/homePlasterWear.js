import * as T from 'three'

// Small, disconnected losses of limewash above the cement base. This is a
// surface overlay, not a second wall or a new continuous line of skirting.
// Colors are linear RGB; use a white, vertex-colored transparent material.
export function createPlasterWearGeometry(width, { seed = 2192015, height = .85 } = {}) {
  if (!Number.isFinite(width) || width <= 0 || !Number.isFinite(height) || height <= 0)
    throw new RangeError('Plaster wear requires positive, finite dimensions')
  let state = seed >>> 0
  const rand = () => { state = (Math.imul(state, 1664525) + 1013904223) >>> 0; return state / 4294967296 }
  const positions = [], colors = [], indices = []
  // A hard cap keeps the treatment below the existing window reveals when
  // attached at y=.28. A shorter caller-supplied height scales the whole band.
  const verticalScale = Math.min(height, .75) / .75
  const palette = ['#96958b', '#aaa79a', '#bcb9ac', '#8e9087', '#c1beb1'].map(c => new T.Color(c))

  function flake(cx, cy, rx, ry, opacity, colorIndex, feather = .13) {
    rx = Math.min(rx, width * .45)
    cx = T.MathUtils.clamp(cx, -width / 2 + rx, width / 2 - rx)
    cy = T.MathUtils.clamp(cy, ry, .75 - ry)
    const count = 9 + Math.floor(rand() * 6), first = positions.length / 3
    const tint = palette[colorIndex], angleOffset = rand() * Math.PI * 2
    const vertex = (x, y, alpha, shade = 1) => {
      positions.push(x, y * verticalScale, 0)
      colors.push(tint.r * shade, tint.g * shade, tint.b * shade, alpha)
    }
    vertex(cx, cy, opacity, .96 + rand() * .06)
    // Each irregular outline has a thin transparent fringe. Most of the chip
    // stays crisp, while the edge does not look like an opaque polygon sticker.
    for (let i = 0; i < count; i++) {
      const angle = angleOffset + (i + (rand() - .5) * .48) / count * Math.PI * 2
      const radius = .57 + rand() * .43
      const x = Math.cos(angle) * rx * radius, y = Math.sin(angle) * ry * radius
      vertex(cx + x * (1 - feather), cy + y * (1 - feather), opacity * (.83 + rand() * .17))
      vertex(cx + x, cy + y, 0)
    }
    for (let i = 0; i < count; i++) {
      const inner = first + 1 + i * 2, next = first + 1 + ((i + 1) % count) * 2
      indices.push(first, inner, next, inner, inner + 1, next + 1, inner, next + 1, next)
    }
  }

  // Clusters are randomly spaced, not sampled along the skirting's sine wave.
  // Most chips hug its upper edge; isolated smaller chips become rare higher up.
  const clusterCount = Math.max(2, Math.ceil(width * 4))
  for (let i = 0; i < clusterCount; i++) {
    const cx = (rand() - .5) * width
    const cy = .365 + Math.pow(rand(), 3.5) * .31
    const pieces = 2 + Math.floor(rand() * 3)
    for (let j = 0; j < pieces; j++) {
      const rx = .018 + Math.pow(rand(), 1.7) * .078
      const ry = .01 + Math.pow(rand(), 1.6) * .055
      const altitude = T.MathUtils.clamp((cy - .36) / .35, 0, 1)
      flake(cx + (rand() - .5) * .23, cy + (rand() - .5) * .065,
        rx * (1 - altitude * .45), ry * (1 - altitude * .45),
        (.3 + rand() * .3) * (1 - altitude * .3), Math.floor(rand() * palette.length))
    }
  }

  // Fine salt-sized pits and a few short rain runs below the chipped band.
  // Their lower opacity avoids turning a weathered facade into graffiti.
  for (let i = 0; i < Math.ceil(width * 4); i++) {
    const elongated = rand() < .16
    flake((rand() - .5) * width, .26 + Math.pow(rand(), 2.8) * .42,
      .004 + rand() * .009, elongated ? .025 + rand() * .06 : .003 + rand() * .007,
      elongated ? .08 + rand() * .08 : .18 + rand() * .16, i % palette.length, elongated ? .4 : .2)
  }

  const geometry = new T.BufferGeometry()
  geometry.setAttribute('position', new T.Float32BufferAttribute(positions, 3))
  geometry.setAttribute('color', new T.Float32BufferAttribute(colors, 4))
  geometry.setIndex(indices)
  geometry.computeVertexNormals()
  geometry.computeBoundingBox()
  geometry.computeBoundingSphere()
  return geometry
}
