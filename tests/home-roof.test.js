import { test } from 'node:test'
import assert from 'node:assert/strict'
import * as T from 'three'
import { createRoofTileGeometry, createRoofTileLayout } from '../src/scene/homeRoof.js'

function inspectSolid(geometry) {
  const { position, normal, uv, color } = geometry.attributes
  assert.ok(geometry.index.count / 3 < 250)
  for (const attribute of Object.values(geometry.attributes)) assert.ok([...attribute.array].every(Number.isFinite))
  assert.equal(position.count, uv.count); assert.equal(position.count, color.count)
  const edges = new Map(), key = index => [position.getX(index), position.getY(index), position.getZ(index)].map(value => value.toFixed(7)).join(',')
  let volume = 0
  for (let i = 0; i < geometry.index.count; i += 3) {
    const ids = [0, 1, 2].map(j => geometry.index.getX(i + j))
    const [a, b, c] = ids.map(index => new T.Vector3().fromBufferAttribute(position, index))
    assert.ok(new T.Vector3().subVectors(b, a).cross(new T.Vector3().subVectors(c, a)).lengthSq() > 1e-16, 'no zero-area faces')
    volume += a.dot(new T.Vector3().crossVectors(b, c)) / 6
    for (let j = 0; j < 3; j++) {
      const edge = [key(ids[j]), key(ids[(j + 1) % 3])].sort().join('|')
      edges.set(edge, (edges.get(edge) || 0) + 1)
    }
  }
  assert.ok([...edges.values()].every(count => count === 2), 'all geometric edges close after welding hard normals')
  const { width, length, thickness } = geometry.userData
  assert.ok(Math.abs(volume - width * length * thickness) < 1e-9, 'outward winding encloses the actual clay thickness')
  for (let i = 0; i < normal.count; i++) assert.ok(Math.abs(new T.Vector3().fromBufferAttribute(normal, i).length() - 1) < 1e-6)
  assert.ok(Math.min(...color.array) > .89 && Math.max(...color.array) <= 1)
}

test('pan and cap roof tiles are closed, thin solids with bounded dimensions and a low triangle budget', () => {
  for (const kind of ['pan', 'cap']) for (const seed of [0, 1, 2015]) {
    const width = kind === 'pan' ? .24 : .1152, geometry = createRoofTileGeometry({ kind, width, seed })
    inspectSolid(geometry)
    const box = geometry.boundingBox
    assert.ok(Math.abs(box.min.x + width / 2) < 1e-7 && Math.abs(box.max.x - width / 2) < 1e-7)
    assert.ok(Math.abs(box.min.z + .21) < 1e-7 && Math.abs(box.max.z - .21) < 1e-7)
    assert.ok(geometry.userData.height < .04 && geometry.userData.segments >= 10)
    geometry.dispose()
  }
})

test('tile profile is a shallow trough or arch, with physical metre UVs and hard mouth normals', () => {
  for (const kind of ['pan', 'cap']) {
    const geometry = createRoofTileGeometry({ kind }), { position, normal, uv } = geometry.attributes
    const segments = geometry.userData.segments, middle = segments / 2
    assert.ok(kind === 'pan' ? position.getY(middle) < position.getY(0) : position.getY(middle) > position.getY(0))
    for (let i = 0; i <= segments; i++) assert.ok(normal.getY(i) > .6, 'top surface normals face daylight')
    assert.ok(Math.abs((uv.getX(segments) - uv.getX(0)) - geometry.userData.width) < 2e-7)
    assert.ok(Math.abs((uv.getY(segments + 1) - uv.getY(0)) - geometry.userData.length) < 2e-7)
    assert.ok([...normal.array].some((value, index) => index % 3 === 2 && Math.abs(value - 1) < 1e-7), 'mouth has a sharp front normal')
    geometry.dispose()
  }
})

test('roof geometry and layout are deterministic while variants subtly vary texture sampling and colour', () => {
  const a = createRoofTileGeometry({ seed: 2 }), b = createRoofTileGeometry({ seed: 2 }), c = createRoofTileGeometry({ seed: 3 })
  for (const name of ['position', 'normal', 'uv', 'color']) assert.deepEqual(a.attributes[name].array, b.attributes[name].array)
  assert.notDeepEqual(a.attributes.uv.array, c.attributes.uv.array)
  assert.notDeepEqual(a.attributes.position.array, c.attributes.position.array)
  for (const geometry of [a, b, c]) geometry.dispose()
  assert.deepEqual(createRoofTileLayout(10, 6), createRoofTileLayout(10, 6))
  assert.notDeepEqual(createRoofTileLayout(10, 6, { seed: 1 }).pans[0].color, createRoofTileLayout(10, 6, { seed: 2 }).pans[0].color)
})

test('layout retains the roof footprint, uses only three variants and avoids a final-row overhang', () => {
  for (const [width, depth, tilt] of [[20.5, 6.8, .11], [20.5, 3.4, .33], [7.35, 3.6, 0], [1.1, .8, -.12], [.2, .3, 0]]) {
    const layout = createRoofTileLayout(width, depth, { tilt }), geometries = new Map()
    assert.equal(layout.pans.length, layout.rows * layout.columns)
    assert.equal(layout.caps.length, layout.rows * (layout.columns - 1))
    assert.ok(layout.pans.length + layout.caps.length < width * depth * 35 + 2, 'bounded instance count')
    for (const tile of [...layout.pans, ...layout.caps]) {
      assert.ok([0, 1, 2].includes(tile.variant))
      const key = `${tile.kind}-${tile.variant}`
      if (!geometries.has(key)) geometries.set(key, createRoofTileGeometry({ ...tile, seed: tile.variant }))
      const geometry = geometries.get(key), transform = new T.Matrix4().compose(new T.Vector3(...tile.position), new T.Quaternion().setFromEuler(new T.Euler(...tile.rotation)), new T.Vector3(1, 1, 1))
      const box = geometry.boundingBox.clone().applyMatrix4(transform)
      assert.ok(box.min.x >= -width / 2 - 1e-7 && box.max.x <= width / 2 + 1e-7, 'tile remains inside roof width')
      assert.ok(box.min.z >= -depth / 2 - 1e-7 && box.max.z <= depth / 2 + 1e-7, 'tile remains inside roof depth')
      assert.ok(tile.color.every(value => value > .85 && value <= 1))
    }
    assert.ok(geometries.size <= 6)
    for (const geometry of geometries.values()) geometry.dispose()
  }
})

test('upstream tile mouths cover downstream tiles by the clay thickness along the original slope', () => {
  for (const tilt of [0, .11, -.1, .33]) {
    const layout = createRoofTileLayout(8, 6.8, { tilt })
    assert.ok(layout.rowStep < layout.length * Math.cos(layout.pitch), 'rows have a real overlap')
    const [upstream, downstream] = [layout.pans[0], layout.pans[layout.columns]]
    const step = downstream.position[2] - upstream.position[2]
    assert.ok(Math.abs(upstream.position[1] - downstream.position[1] - Math.tan(tilt) * step) < 1e-12)
    const lapLift = step * (Math.tan(tilt) - Math.tan(layout.pitch))
    assert.ok(lapLift > layout.thickness && lapLift < layout.thickness + .003, 'mouth sits one thickness above the tile beneath it')
  }
  const sparseRequest = createRoofTileLayout(8, 6.8, { rowStep: 1 })
  assert.ok(sparseRequest.rowStep < sparseRequest.length * .81, 'even a wide requested row step cannot leave open gaps')
})

test('a twenty-degree roof follows its true planar pitch on both gable slopes', () => {
  const tilt = 20 * Math.PI / 180, depth = 3.4, layout = createRoofTileLayout(14.8, depth, { tilt })
  for (const tile of [...layout.pans, ...layout.caps]) {
    const local = new T.Vector3(...tile.position)
    const rise = tile.kind === 'cap' ? layout.thickness + layout.panWidth * .11 * (1 - layout.capWidth / layout.panWidth) ** 2 : 0
    const planeY = local.y - layout.thickness - rise
    assert.ok(Math.abs(planeY + local.z * Math.tan(tilt)) < 1e-12, 'front slope agrees exactly with its gable plane')
    const back = local.clone().applyAxisAngle(new T.Vector3(0, 1, 0), Math.PI)
    assert.ok(Math.abs(planeY - back.z * Math.tan(tilt)) < 1e-12, 'rotating the group by pi produces the opposite slope without changing its height')
  }
  const first = layout.pans[0], last = layout.pans[(layout.rows - 1) * layout.columns]
  const run = last.position[2] - first.position[2]
  assert.ok(Math.abs((first.position[1] - last.position[1]) / run - Math.tan(tilt)) < 1e-12)
})

test('invalid dimensions and unsupported tile kinds are rejected without creating invalid geometry', () => {
  for (const value of [0, -1, Infinity, NaN]) {
    assert.throws(() => createRoofTileGeometry({ width: value }), RangeError)
    assert.throws(() => createRoofTileLayout(5, value), RangeError)
  }
  assert.throws(() => createRoofTileGeometry({ kind: 'ridge' }), RangeError)
  assert.throws(() => createRoofTileGeometry({ thickness: .2 }), RangeError)
  assert.throws(() => createRoofTileLayout(5, 5, { tilt: 2 }), RangeError)
  assert.throws(() => createRoofTileLayout(5, 5, { seed: NaN }), RangeError)
})
