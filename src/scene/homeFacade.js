import { ExtrudeGeometry, Float32BufferAttribute, Path, Shape } from 'three'
import { houseEntry, houseWindowCenterY, houseWindowOpeningHeight } from './homeLayout.js'

const doorWidth = houseEntry.openingWidth
const doorHeight = 3.76
const textureUnit = 4

/**
 * A thin, solid facade with open window reveals and a bottom-open door notch.
 * Coordinates are house-local; the courtyard-facing surface is at +depth / 2.
 * The caller owns/disposes the returned geometry, like other scene geometries.
 */
export function createFacadeGeometry({ minX, maxX, height = 3.8, depth = .3, doorX, windows = [] }) {
  if (![minX, maxX, height, depth, doorX].every(Number.isFinite)) {
    throw new TypeError('Facade dimensions must be finite numbers')
  }
  const doorLeft = doorX - doorWidth / 2
  const doorRight = doorX + doorWidth / 2
  if (depth <= 0 || height <= doorHeight || minX >= doorLeft || maxX <= doorRight) {
    throw new RangeError('Facade must fully surround the door on both sides and above')
  }

  const openings = windows.map(({ x, width }) => {
    if (!Number.isFinite(x) || !Number.isFinite(width) || width <= 0) {
      throw new TypeError('Window positions and widths must be finite, with positive widths')
    }
    const opening = { left: x - (width + .08) / 2, right: x + (width + .08) / 2 }
    if (opening.left <= minX || opening.right >= maxX ||
        (opening.left < doorRight && opening.right > doorLeft)) {
      throw new RangeError('Window openings must stay inside the facade and separate from the door')
    }
    return opening
  }).sort((a, b) => a.left - b.left)
  for (let i = 1; i < openings.length; i++) {
    if (openings[i].left <= openings[i - 1].right) {
      throw new RangeError('Window openings must not touch or overlap')
    }
  }

  // The door is part of the perimeter, never a hole touching its bottom edge.
  const outline = new Shape()
  outline.moveTo(minX, 0)
  outline.lineTo(doorLeft, 0)
  outline.lineTo(doorLeft, doorHeight)
  outline.lineTo(doorRight, doorHeight)
  outline.lineTo(doorRight, 0)
  outline.lineTo(maxX, 0)
  outline.lineTo(maxX, height)
  outline.lineTo(minX, height)
  outline.closePath()
  const bottom = houseWindowCenterY - houseWindowOpeningHeight / 2
  const top = houseWindowCenterY + houseWindowOpeningHeight / 2
  for (const { left, right } of openings) {
    const hole = new Path()
    hole.moveTo(left, bottom)
    hole.lineTo(left, top)
    hole.lineTo(right, top)
    hole.lineTo(right, bottom)
    hole.closePath()
    outline.holes.push(hole)
  }

  const geometry = new ExtrudeGeometry(outline, { depth, steps: 1, bevelEnabled: false, curveSegments: 1 })
  geometry.translate(0, 0, -depth / 2)

  // Use the same 4 m texture scale on caps, jambs, heads and sills. Extrude's
  // default side-wall UVs normalize depth, stretching plaster across reveals.
  const positions = geometry.getAttribute('position')
  const normals = geometry.getAttribute('normal')
  const uv = new Float32Array(positions.count * 2)
  for (let i = 0; i < positions.count; i++) {
    const x = positions.getX(i), y = positions.getY(i), z = positions.getZ(i)
    if (Math.abs(normals.getZ(i)) > .5) {
      uv[i * 2] = x / textureUnit
      uv[i * 2 + 1] = y / textureUnit
    } else if (Math.abs(normals.getX(i)) > .5) {
      uv[i * 2] = z / textureUnit
      uv[i * 2 + 1] = y / textureUnit
    } else {
      uv[i * 2] = x / textureUnit
      uv[i * 2 + 1] = z / textureUnit
    }
  }
  geometry.setAttribute('uv', new Float32BufferAttribute(uv, 2))
  geometry.computeBoundingBox()
  geometry.computeBoundingSphere()
  return geometry
}
