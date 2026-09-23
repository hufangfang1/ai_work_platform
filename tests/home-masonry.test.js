import { test } from 'node:test'
import assert from 'node:assert/strict'
import * as T from 'three'
import { createMasonryGeometry } from '../src/scene/homeMasonry.js'

function dispose(result) { result.bricks.dispose(); result.mortar.dispose() }

function validateFaces(geometry) {
  const { position, normal } = geometry.attributes, indices = geometry.index
  const a = new T.Vector3(), b = new T.Vector3(), c = new T.Vector3(), ab = new T.Vector3(), ac = new T.Vector3(), n = new T.Vector3(), average = new T.Vector3()
  for (let i = 0; i < indices.count; i += 3) {
    const ia = indices.getX(i), ib = indices.getX(i + 1), ic = indices.getX(i + 2)
    a.fromBufferAttribute(position, ia); b.fromBufferAttribute(position, ib); c.fromBufferAttribute(position, ic)
    n.crossVectors(ab.subVectors(b, a), ac.subVectors(c, a))
    assert.ok(n.lengthSq() > 1e-18, `non-degenerate triangle ${i / 3}`)
    average.fromBufferAttribute(normal, ia).add(new T.Vector3().fromBufferAttribute(normal, ib)).add(new T.Vector3().fromBufferAttribute(normal, ic))
    assert.ok(n.dot(average) > 0, `normal follows outward winding ${i / 3}`)
  }
  for (let i = 0; i < normal.count; i++) assert.ok(Math.abs(n.fromBufferAttribute(normal, i).length() - 1) < 1e-5)
}

test('wall and deep gate-pillar masonry stay within their exact layout footprint', () => {
  for (const [width, height, depth] of [[16, 2.3, .35], [.42, 3.9, 1.8], [.1, .09, .06]]) {
    const result = createMasonryGeometry(width, height, depth)
    for (const geometry of Object.values(result)) {
      const bounds = geometry.boundingBox, tolerance = 1e-6
      assert.ok(bounds.min.x >= -width / 2 - tolerance && bounds.max.x <= width / 2 + tolerance)
      assert.ok(bounds.min.y >= -tolerance && bounds.max.y <= height + tolerance)
      assert.ok(bounds.min.z >= -depth / 2 - tolerance && bounds.max.z <= depth / 2 + tolerance)
      for (const key of ['position', 'normal', 'uv', 'color']) {
        assert.ok([...geometry.attributes[key].array].every(Number.isFinite), `${key} stays finite`)
        assert.equal(geometry.attributes[key].count, geometry.attributes.position.count)
      }
      assert.ok([...geometry.attributes.color.array].every(value => value >= 0 && value <= 1))
      validateFaces(geometry)
    }
    dispose(result)
  }
})

test('all four sides have front-facing brick surfaces and physically recessed joints', () => {
  const result = createMasonryGeometry(3, 1.7, .35), { bricks, mortar } = result
  const { position, normal, uv } = bricks.attributes
  const directions = [new T.Vector3(1, 0, 0), new T.Vector3(-1, 0, 0), new T.Vector3(0, 0, 1), new T.Vector3(0, 0, -1)]
  for (const direction of directions) {
    let faces = 0, minimumRelief = Infinity, maximumRelief = -Infinity
    const faceDepth = Math.abs(direction.x) ? 1.5 : .175
    for (let i = 0; i < position.count; i++) {
      if (new T.Vector3().fromBufferAttribute(normal, i).dot(direction) < .99) continue
      const outward = position.getX(i) * direction.x + position.getZ(i) * direction.z
      // Brick edge bevels on a perpendicular wall may share this normal.
      // Only test the outward skin belonging to the selected wall plane.
      if (outward < faceDepth - .006) continue
      const expectedU = direction.x ? -direction.x * position.getZ(i) : direction.z * position.getX(i)
      if (Math.abs(uv.getX(i) - expectedU) > 1e-6) continue
      faces++
      const relief = outward - (faceDepth - bricks.userData.recess)
      minimumRelief = Math.min(minimumRelief, relief); maximumRelief = Math.max(maximumRelief, relief)
      assert.ok(Math.abs(uv.getY(i) - position.getY(i)) < 1e-6, 'vertical UVs remain world-sized across all bricks')
    }
    assert.ok(faces > 10, `real brick faces on ${direction.toArray()}`)
    assert.ok(minimumRelief >= .008 && maximumRelief <= .0121, `${minimumRelief}–${maximumRelief} m relief`)
  }
  assert.ok(Math.abs(mortar.boundingBox.max.z - (.175 - .013)) < 1e-6)
  dispose(result)
})

test('weathering, chipped corners, bond and colors are seeded but not uniform', () => {
  const first = createMasonryGeometry(2.5, 1.3, .35, { seed: 71 })
  const second = createMasonryGeometry(2.5, 1.3, .35, { seed: 71 })
  const other = createMasonryGeometry(2.5, 1.3, .35, { seed: 72 })
  assert.deepEqual(first.bricks.attributes.position.array, second.bricks.attributes.position.array)
  assert.deepEqual(first.bricks.attributes.color.array, second.bricks.attributes.color.array)
  assert.notDeepEqual(first.bricks.attributes.position.array, other.bricks.attributes.position.array)
  assert.ok(new Set([...first.bricks.attributes.color.array].map(value => value.toFixed(4))).size > 100)
  assert.ok(first.bricks.index.count / 3 > first.bricks.userData.brickCount * 10, 'some worn corners have additional geometry')
  for (const result of [first, second, other]) dispose(result)
})

test('a full 16 metre wall stays below the triangle budget in just two geometries', () => {
  const result = createMasonryGeometry(16, 2.3, .35)
  assert.deepEqual(Object.keys(result).sort(), ['bricks', 'mortar'])
  const triangles = Object.values(result).reduce((sum, geometry) => sum + geometry.index.count / 3, 0)
  assert.ok(triangles < 60000, `${triangles} triangles, under 60k`)
  assert.ok(result.bricks.userData.brickCount > 3500, 'both long sides are physically modelled')
  assert.equal(result.bricks.groups.length, 0)
  assert.equal(result.mortar.groups.length, 0)
  dispose(result)
})

test('invalid dimensions and non-finite parameters fail before allocating geometry', () => {
  for (const dimensions of [[0, 1, 1], [1, -1, 1], [1, 1, Infinity], [NaN, 1, 1]]) {
    assert.throws(() => createMasonryGeometry(...dimensions), RangeError)
  }
  assert.throws(() => createMasonryGeometry(1, 1, .3, { brickLength: 0 }), RangeError)
  assert.throws(() => createMasonryGeometry(1, 1, .3, { courseHeight: NaN }), RangeError)
  assert.throws(() => createMasonryGeometry(1, 1, .3, { seed: Infinity }), RangeError)
})
