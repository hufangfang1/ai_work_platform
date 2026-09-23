import * as T from 'three'

const seeded = value => {
  let seed = value >>> 0
  return () => { seed = (seed * 1664525 + 1013904223) >>> 0; return seed / 4294967296 }
}

const brickPalette = ['#978b79', '#9c8976', '#92816f', '#898173', '#a0927f', '#938a7b'].map(color => new T.Color(color))

/**
 * Four-sided, solid-backed old masonry. X/Z centered, bottom at Y=0.
 * Returns { bricks, mortar }: two caller-owned BufferGeometries, one draw call
 * each. No part protrudes beyond width × height × depth. The mortar bed is
 * 13 mm inside the footprint, leaving 9–12 mm relief behind the brick faces.
 * Narrow inputs scale the relief down to keep the core positive.
 *
 * Both geometries contain LINEAR vertex colors and metre-scale UVs (1 UV unit
 * = 1 metre). Use white MeshStandardMaterials with vertexColors, roughness: 1,
 * metalness: 0; optional maps should contain only fine, neutral clay/grit.
 * Do NOT map an entire photographed/procedural brick wall onto each brick.
 * The core closes the surface behind all joints and at the top and bottom;
 * brick skins need no invisible rear faces. Caps may be added separately.
 */
export function createMasonryGeometry(width, height, depth, { seed = 2015, brickLength = .25, courseHeight = .08 } = {}) {
  if (![width, height, depth, brickLength, courseHeight].every(value => Number.isFinite(value) && value > 0)) {
    throw new RangeError('Masonry dimensions and brick sizes must be positive and finite')
  }
  if (!Number.isFinite(seed)) throw new RangeError('Masonry seed must be finite')
  const rand = seeded(seed), recess = Math.min(.013, width * .12, depth * .12, height * .12)
  const positions = [], uvs = [], colors = [], indices = []
  let brickCount = 0
  const courseCount = Math.max(1, Math.round(height / courseHeight))
  // Slightly unequal courses preserve a hand-laid appearance without large,
  // implausible rotations or wide gaps between a stack of separate blocks.
  const courses = Array.from({ length: courseCount }, () => .94 + rand() * .12)
  const courseScale = height / courses.reduce((sum, value) => sum + value, 0)
  const phase = rand() * Math.PI * 2

  function vertex(point, orientation, color, shade) {
    const index = positions.length / 3, [u, y, outward] = point
    const [axisX, axisZ, normalX, normalZ] = orientation
    positions.push(axisX * u + normalX * outward, y, axisZ * u + normalZ * outward)
    uvs.push(u, y)
    colors.push(color.r * shade, color.g * shade, color.b * shade)
    return index
  }

  function addBrick(left, right, bottom, top, faceDepth, orientation, color) {
    const brickWidth = right - left, brickHeight = top - bottom
    if (brickWidth < .002 || brickHeight < .002) return
    brickCount++
    const bevel = Math.min(.0025 + rand() * .002, brickWidth * .12, brickHeight * .12)
    const faceInset = recess * (.08 + rand() * .22)
    const backDepth = faceDepth - recess + .00005
    const frontDepth = faceDepth - faceInset
    // Most bricks have worn quadrilateral faces; occasional clipped corners
    // interrupt the machine-made silhouette without turning every brick into
    // a uniformly rounded "Lego" block. All damage cuts inward.
    const chipCorner = rand() < .23 ? Math.floor(rand() * 4) : -1
    const chip = Math.min(.005 + rand() * .008, brickWidth * .17, brickHeight * .25)
    const corners = [[left, bottom], [right, bottom], [right, top], [left, top]]
    const inner = [
      [left + bevel, bottom + bevel * (.7 + rand() * .5)],
      [right - bevel, bottom + bevel * (.7 + rand() * .5)],
      [right - bevel * (.8 + rand() * .4), top - bevel],
      [left + bevel * (.8 + rand() * .4), top - bevel],
    ]
    const front = [], back = []
    for (let i = 0; i < 4; i++) {
      if (i !== chipCorner) {
        front.push([...inner[i], frontDepth - rand() * .00065])
        back.push([...corners[i], backDepth])
      } else {
        for (const next of [(i + 3) % 4, (i + 1) % 4]) {
          const alongX = corners[next][0] !== corners[i][0]
          const amount = chip / (alongX ? brickWidth : brickHeight)
          front.push([
            inner[i][0] + (inner[next][0] - inner[i][0]) * amount,
            inner[i][1] + (inner[next][1] - inner[i][1]) * amount,
            frontDepth - rand() * .001,
          ])
          back.push([
            corners[i][0] + (corners[next][0] - corners[i][0]) * amount * .32,
            corners[i][1] + (corners[next][1] - corners[i][1]) * amount * .32,
            backDepth,
          ])
        }
      }
    }
    const face = front.map(point => vertex(point, orientation, color, .975 + rand() * .04))
    for (let i = 1; i < face.length - 1; i++) indices.push(face[0], face[i], face[i + 1])
    // Separate face normals from the bevel: soft color wear must not turn a
    // flat old brick into a swollen, smoothly shaded pillow.
    for (let i = 0; i < front.length; i++) {
      const next = (i + 1) % front.length
      const a = vertex(back[i], orientation, color, .79 + rand() * .06)
      const b = vertex(back[next], orientation, color, .79 + rand() * .06)
      const c = vertex(front[i], orientation, color, .94)
      const d = vertex(front[next], orientation, color, .94)
      indices.push(a, b, c, b, d, c)
    }
  }

  // axisU cross axisY = outward normal, so every side shares one outward
  // winding convention. World-sized UVs continue across individual bricks.
  for (const [span, faceDepth, orientation] of [
    [width, depth / 2, [1, 0, 0, 1]],
    [width, depth / 2, [-1, 0, 0, -1]],
    [depth, width / 2, [0, -1, 1, 0]],
    [depth, width / 2, [0, 1, -1, 0]],
  ]) {
    let baseY = 0
    for (let row = 0; row < courseCount; row++) {
      const nextY = baseY + courses[row] * courseScale
      // Occasional header courses belong to the bond, not random tiny bricks.
      const nominal = brickLength * (row % 6 === 4 ? .52 : 1)
      let left = -span / 2 - nominal * (row % 2 ? .48 : .08) - rand() * nominal * .08
      while (left < span / 2) {
        const right = left + nominal * (.91 + rand() * .18)
        const clippedLeft = Math.max(left, -span / 2), clippedRight = Math.min(right, span / 2)
        const length = clippedRight - clippedLeft
        const gap = Math.min(.004 + rand() * .0018, length * .08)
        const middle = (clippedLeft + clippedRight) / 2
        const bow = Math.sin(middle * 1.3 + row * .57 + phase) * .0014
        const verticalGap = Math.min(.004 + rand() * .001, (nextY - baseY) * .09)
        const lower = Math.max(0, baseY + verticalGap + bow)
        const upper = Math.min(height, nextY - verticalGap + bow)
        const color = brickPalette[Math.floor(rand() * brickPalette.length)].clone()
        // A faint ground-level damp/dirt band and uneven fading are built into
        // the surface, avoiding high-contrast randomly colored checkerboards.
        const weather = 1 - Math.max(0, 1 - lower / .34) * (.07 + rand() * .045)
        color.multiplyScalar(weather * (.955 + rand() * .075))
        addBrick(clippedLeft + gap, clippedRight - gap, lower, upper, faceDepth, orientation, color)
        left = right
      }
      baseY = nextY
    }
  }

  const bricks = new T.BufferGeometry()
  bricks.setAttribute('position', new T.Float32BufferAttribute(positions, 3))
  bricks.setAttribute('uv', new T.Float32BufferAttribute(uvs, 2))
  bricks.setAttribute('color', new T.Float32BufferAttribute(colors, 3))
  bricks.setIndex(indices)
  bricks.computeVertexNormals(); bricks.computeBoundingBox(); bricks.computeBoundingSphere()
  bricks.userData = { brickCount, dimensions: [width, height, depth], uvMetres: 1, recess, seed }

  const mortar = new T.BoxGeometry(width - recess * 2, height, depth - recess * 2)
  mortar.translate(0, height / 2, 0)
  const mortarPositions = mortar.attributes.position, mortarNormals = mortar.attributes.normal
  const mortarColors = [], mortarColor = new T.Color('#827e70')
  for (let i = 0; i < mortarPositions.count; i++) {
    const x = mortarPositions.getX(i), y = mortarPositions.getY(i), z = mortarPositions.getZ(i)
    const u = Math.abs(mortarNormals.getX(i)) > .5 ? z : x
    mortar.attributes.uv.setXY(i, u, Math.abs(mortarNormals.getY(i)) > .5 ? z : y)
    const shade = y > .2 ? 1 : .91
    mortarColors.push(mortarColor.r * shade, mortarColor.g * shade, mortarColor.b * shade)
  }
  mortar.setAttribute('color', new T.Float32BufferAttribute(mortarColors, 3))
  mortar.clearGroups()
  mortar.computeBoundingBox(); mortar.computeBoundingSphere()
  mortar.userData = { recess, dimensions: [width, height, depth], uvMetres: 1 }
  return { bricks, mortar }
}
