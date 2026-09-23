import { test } from 'node:test'
import assert from 'node:assert/strict'
import * as T from 'three'
import { createTimberGeometry, createWoodenDoorGeometry } from '../src/scene/homeJoinery.js'

function inspect(geometry, dimensions, triangleLimit) {
  const { position, normal, uv, color } = geometry.attributes
  for (const [name, attribute] of Object.entries(geometry.attributes)) assert.ok([...attribute.array].every(Number.isFinite), `${name} is finite`)
  assert.ok(geometry.index.count / 3 < triangleLimit)
  assert.equal(uv.count, position.count); assert.equal(color.count, position.count)
  const size = geometry.boundingBox.getSize(new T.Vector3()), center = geometry.boundingBox.getCenter(new T.Vector3())
  for (const [axis, dimension] of ['x', 'y', 'z'].map((axis, i) => [axis, dimensions[i]])) {
    assert.ok(Math.abs(size[axis] - dimension) < 1e-6, `${axis} footprint stays fixed`)
    assert.ok(Math.abs(center[axis]) < 1e-7, `${axis} stays centered`)
  }
  for (let i = 0; i < normal.count; i++) assert.ok(Math.abs(new T.Vector3().fromBufferAttribute(normal, i).length() - 1) < 1e-6, 'unit normals')
  assert.ok(Math.min(...color.array) > .91 && Math.max(...color.array) <= 1, 'only subtle pale wear and dust')
  let volume = 0
  const edges = new Map(), key = i => [position.getX(i), position.getY(i), position.getZ(i)].map(value => value.toFixed(7)).join(',')
  for (let i = 0; i < geometry.index.count; i += 3) {
    const vertices = [0, 1, 2].map(j => geometry.index.getX(i + j)), [a, b, c] = vertices.map(index => new T.Vector3().fromBufferAttribute(position, index))
    assert.ok(new T.Vector3().subVectors(b, a).cross(new T.Vector3().subVectors(c, a)).lengthSq() > 1e-19, 'no zero-area faces')
    volume += a.dot(new T.Vector3().crossVectors(b, c)) / 6
    for (let j = 0; j < 3; j++) {
      const edge = [key(vertices[j]), key(vertices[(j + 1) % 3])].sort().join('|')
      edges.set(edge, (edges.get(edge) || 0) + 1)
    }
  }
  assert.ok(volume > dimensions.reduce((result, value) => result * value, 1) * .87, 'outward winding encloses a solid, not a hollow face')
  assert.ok([...edges.values()].every(count => count === 2), 'all geometric edges are watertight after welding normal/UV seams')
}

test('hard timber has bounded millimetre bevels, a solid footprint and valid faces', () => {
  const geometry = createTimberGeometry(.13, 2.45, .16, { seed: 11 })
  inspect(geometry, [.13, 2.45, .16], 1000)
  assert.ok(geometry.userData.bevel >= .002 && geometry.userData.bevel <= .006)
  const position = geometry.attributes.position
  assert.ok([...position.array].some((value, index) => index % 3 === 2 && Math.abs(value - .08) < 1e-7), 'large front surface remains flat at the original depth')
  geometry.dispose()
})

test('timber maps physical grain along V for vertical posts and horizontal lintels', () => {
  for (const grainAxis of ['x', 'y']) {
    const dimensions = grainAxis === 'x' ? [3.2, .13, .16] : [.13, 3.2, .16]
    const geometry = createTimberGeometry(...dimensions, { grainAxis, seed: 19 })
    inspect(geometry, dimensions, 1000)
    const { position, uv } = geometry.attributes, getLong = i => grainAxis === 'x' ? position.getX(i) : position.getY(i)
    // Each long-side quad is stored in a,b,c,d order before the end caps.
    const a = 8 * 4, d = a + 3
    assert.ok(Math.abs((uv.getY(d) - uv.getY(a)) - (getLong(d) - getLong(a)) / 3) < 1e-7)
    assert.ok(Math.abs(uv.getY(a + 1) - uv.getY(a)) < 1e-7, 'transverse movement does not turn the wood grain sideways')
    geometry.dispose()
  }
})

test('joinery wear is deterministic but different seeds vary bevels and boards', () => {
  for (const factory of [() => createTimberGeometry(.13, 2.4, .16), () => createWoodenDoorGeometry()]) {
    const a = factory(), b = factory()
    for (const name of ['position', 'normal', 'uv', 'color']) assert.deepEqual(a.attributes[name].array, b.attributes[name].array)
    a.dispose(); b.dispose()
  }
  const a = createTimberGeometry(.13, 2.4, .16, { seed: 1 }), b = createTimberGeometry(.13, 2.4, .16, { seed: 2 })
  assert.notDeepEqual(a.attributes.position.array, b.attributes.position.array)
  a.dispose(); b.dispose()
  const doorA = createWoodenDoorGeometry(1.28, 2.65, .075, { seed: 1 }), doorB = createWoodenDoorGeometry(1.28, 2.65, .075, { seed: 2 })
  assert.notDeepEqual(doorA.attributes.position.array, doorB.attributes.position.array)
  doorA.dispose(); doorB.dispose()
})

test('plain door is one closed slab with narrow shallow seams, not open plank gaps', () => {
  const geometry = createWoodenDoorGeometry()
  inspect(geometry, [1.28, 2.65, .075], 8000)
  assert.equal(geometry.userData.seamWidth, .003)
  assert.ok(geometry.userData.seamDepth <= .0015)
  const position = geometry.attributes.position
  const frontInterior = []
  for (let i = 0; i < position.count; i++) if (Math.abs(position.getX(i)) < .62 && Math.abs(position.getY(i)) < 1.2 && position.getZ(i) > 0) frontInterior.push(position.getZ(i))
  assert.ok(Math.max(...frontInterior) - Math.min(...frontInterior) < .0015, 'the door face is a solid plane, with only tiny joints')
  geometry.dispose()
})

test('door front UVs remain physical across every board instead of restarting at each seam', () => {
  const geometry = createWoodenDoorGeometry(.93, 2.2, .055, { seed: 0 })
  const { position, normal, uv } = geometry.attributes
  const offsetU = uv.getX(0) - position.getX(0), offsetV = uv.getY(0) - position.getY(0) / 3
  let checked = 0
  for (let i = 0; i < position.count; i++) if (normal.getZ(i) > .1) {
    assert.ok(Math.abs(uv.getX(i) - position.getX(i) - offsetU) < 1e-7, 'one metre across grain is one U repeat')
    assert.ok(Math.abs(uv.getY(i) - position.getY(i) / 3 - offsetV) < 1e-7, 'three metres along grain is one V repeat')
    checked++
  }
  assert.ok(checked > 100)
  geometry.dispose()
})

test('different window and door sizes stay closed and centered for every seed', () => {
  for (const seed of [0, -2015, 37, 0x7fffffff]) {
    for (const [dimensions, grainAxis] of [[[.065, 2.8, .08], 'y'], [[3.6, .09, .12], 'x']]) {
      const geometry = createTimberGeometry(...dimensions, { seed, grainAxis })
      inspect(geometry, dimensions, 1000)
      geometry.dispose()
    }
    for (const dimensions of [[.85, 2.1, .04], [1.4, 2.9, .1]]) {
      const geometry = createWoodenDoorGeometry(...dimensions, { seed })
      inspect(geometry, dimensions, 8000)
      geometry.dispose()
    }
  }
})

test('small crossbars remain finite, while invalid dimensions and grain directions are rejected', () => {
  const geometry = createTimberGeometry(.6, .032, .028, { grainAxis: 'x' })
  inspect(geometry, [.6, .032, .028], 1000)
  geometry.dispose()
  for (const value of [0, -1, Infinity, NaN]) {
    assert.throws(() => createTimberGeometry(value, 1, .1), RangeError)
    assert.throws(() => createWoodenDoorGeometry(1, value, .075), RangeError)
  }
  assert.throws(() => createTimberGeometry(1, 1, .1, { grainAxis: 'z' }), RangeError)
  assert.throws(() => createTimberGeometry(1, 1, .1, { seed: Infinity }), RangeError)
  assert.throws(() => createWoodenDoorGeometry(1, 2, .075, { seed: NaN }), RangeError)
  assert.throws(() => createWoodenDoorGeometry(1, 2, '0.075'), RangeError)
})
