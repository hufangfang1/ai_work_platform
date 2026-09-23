import test from 'node:test'
import assert from 'node:assert/strict'
import * as T from 'three'
import { createPlasterWearGeometry } from '../src/scene/homePlasterWear.js'

test('plaster chips are finite, flat, front-facing and keep within the wall and window boundaries', () => {
  for (const [width, height] of [[15, .85], [.12, .85], [3, .3]]) {
    const geometry = createPlasterWearGeometry(width, { height })
    const p = geometry.attributes.position, c = geometry.attributes.color, n = geometry.attributes.normal
    assert.equal(c.itemSize, 4)
    for (const attribute of [p, c, n]) assert.ok([...attribute.array].every(Number.isFinite))
    for (let i = 0; i < p.count; i++) {
      assert.ok(Math.abs(p.getX(i)) <= width / 2 + 1e-6)
      assert.ok(p.getY(i) >= 0 && p.getY(i) <= Math.min(height, .75) + 1e-6)
      assert.equal(p.getZ(i), 0)
      assert.ok(n.getZ(i) > .999, `front-facing normal at vertex ${i}`)
      assert.ok(c.getW(i) >= 0 && c.getW(i) <= .6)
      for (const value of [c.getX(i), c.getY(i), c.getZ(i)]) assert.ok(value > 0 && value < .6)
    }
    assert.ok(geometry.index.count / 3 < 12000)
    geometry.dispose()
  }
})

test('plaster treatment has no degenerate or inverted triangles', () => {
  const geometry = createPlasterWearGeometry(15), p = geometry.attributes.position, index = geometry.index
  const a = new T.Vector3(), b = new T.Vector3(), c = new T.Vector3()
  for (let i = 0; i < index.count; i += 3) {
    a.fromBufferAttribute(p, index.getX(i)); b.fromBufferAttribute(p, index.getX(i + 1)); c.fromBufferAttribute(p, index.getX(i + 2))
    const signedArea = (b.x - a.x) * (c.y - a.y) - (b.y - a.y) * (c.x - a.x)
    assert.ok(signedArea > 1e-12, `positive, nonzero face ${i / 3}`)
  }
  geometry.dispose()
})

test('chips have feathered boundaries and quickly diminish above the lower band', () => {
  const geometry = createPlasterWearGeometry(15), p = geometry.attributes.position, c = geometry.attributes.color
  let edgeCount = 0, coreCount = 0, lowWeight = 0, highWeight = 0
  for (let i = 0; i < p.count; i++) {
    const alpha = c.getW(i)
    if (alpha === 0) edgeCount++
    else coreCount++
    if (p.getY(i) < .5) lowWeight += alpha
    if (p.getY(i) > .6) highWeight += alpha
  }
  assert.ok(edgeCount > coreCount * .8 && edgeCount < coreCount)
  assert.ok(lowWeight > highWeight * 8, 'only sparse faint chips extend toward the windows')
  assert.ok(new Set([...c.array].filter((_, i) => i % 4 === 3)).size > 50, 'alpha does not form a uniform stamped row')
  geometry.dispose()
})

test('plaster wear is seed-stable without sharing or consuming external randomness', () => {
  const first = createPlasterWearGeometry(8), second = createPlasterWearGeometry(8), other = createPlasterWearGeometry(8, { seed: 2000 })
  assert.deepEqual(first.attributes.position.array, second.attributes.position.array)
  assert.deepEqual(first.attributes.color.array, second.attributes.color.array)
  assert.deepEqual(first.index.array, second.index.array)
  assert.notDeepEqual(first.attributes.position.array, other.attributes.position.array)
  for (const geometry of [first, second, other]) geometry.dispose()
  for (const width of [0, -1, Infinity, NaN]) assert.throws(() => createPlasterWearGeometry(width), RangeError)
  assert.throws(() => createPlasterWearGeometry(1, { height: 0 }), RangeError)
  assert.throws(() => createPlasterWearGeometry(1, { height: NaN }), RangeError)
})
