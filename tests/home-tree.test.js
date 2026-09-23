import assert from 'node:assert/strict'
import test from 'node:test'
import { createWinterTreeGeometry } from '../src/scene/homeTree.js'

test('winter tree is one indexed, finite, correctly shaded geometry', () => {
  const geometry = createWinterTreeGeometry(1979)
  for (const name of ['position', 'normal', 'uv']) {
    assert.ok(Array.from(geometry.attributes[name].array).every(Number.isFinite), name)
  }
  assert.equal(geometry.attributes.position.count, geometry.attributes.normal.count)
  assert.equal(geometry.attributes.position.count, geometry.attributes.uv.count)
  assert.ok(geometry.index.count / 3 < 35000)
  assert.ok(geometry.userData.branchCount > 90)
  const normal = geometry.attributes.normal
  for (let i = 0; i < normal.count; i++) {
    assert.ok(Math.abs(Math.hypot(normal.getX(i), normal.getY(i), normal.getZ(i)) - 1) < .0001)
  }
  // The first ring is centred on the tree base: its side normals must point
  // outward, otherwise FrontSide bark would render hollow under backlighting.
  const position = geometry.attributes.position
  for (let i = 0; i < 14; i++) {
    assert.ok(position.getX(i) * normal.getX(i) + position.getZ(i) * normal.getZ(i) > 0)
  }
  geometry.dispose()
})

test('winter tree fits the established courtyard scale and is rooted on the ground', () => {
  for (const seed of [0, 1979, 2015, 41321, -3]) {
    const geometry = createWinterTreeGeometry(seed)
    const { min, max } = geometry.boundingBox
    assert.equal(min.y, 0)
    assert.ok(max.y > 7.4 && max.y < 9, `height ${max.y}`)
    assert.ok(max.x - min.x > 2.3 && max.x - min.x < 7)
    assert.ok(max.z - min.z > 2.3 && max.z - min.z < 7)
    geometry.dispose()
  }
})

test('tree seed reproduces geometry and different seeds change the silhouette', () => {
  const first = createWinterTreeGeometry(1234)
  const same = createWinterTreeGeometry(1234)
  const other = createWinterTreeGeometry(4321)
  assert.deepEqual(first.attributes.position.array, same.attributes.position.array)
  assert.deepEqual(first.index.array, same.index.array)
  assert.notDeepEqual(first.attributes.position.array, other.attributes.position.array)
  first.dispose(); same.dispose(); other.dispose()
})

test('far trees retain a complete silhouette with a lower geometry budget', () => {
  const near = createWinterTreeGeometry(2015)
  const far = createWinterTreeGeometry(2015, { detail: 'far' })
  assert.ok(far.index.count < near.index.count * .48)
  assert.ok(far.index.count / 3 < 8000)
  assert.ok(far.userData.branchCount > 35)
  assert.ok(Math.abs(near.boundingBox.max.y - far.boundingBox.max.y) < .3)
  near.dispose(); far.dispose()
})

test('tree API rejects unsupported detail and invalid seeds', () => {
  assert.throws(() => createWinterTreeGeometry(NaN), TypeError)
  assert.throws(() => createWinterTreeGeometry(1, { detail: 'unknown' }), RangeError)
})
