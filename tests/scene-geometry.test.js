import assert from 'node:assert/strict'
import test from 'node:test'
import * as THREE from 'three'
import { sculptCloud, sculptEar } from '../src/scene/cloudSculpt.js'
import { createFurSurface } from '../src/scene/furSurface.js'

function assertFiniteAttribute(attribute, name) {
  assert.ok(attribute, `${name} exists`)
  for (const value of attribute.array) assert.ok(Number.isFinite(value), `${name} contains only finite values`)
}

function assertHealthyGeometry(geometry, minimumFaces, maximumFaces) {
  const positions = geometry.getAttribute('position')
  const normals = geometry.getAttribute('normal')
  assertFiniteAttribute(positions, 'positions')
  assertFiniteAttribute(normals, 'normals')
  assert.equal(normals.count, positions.count)
  const index = geometry.getIndex()
  const faceCount = (index?.count ?? positions.count) / 3
  assert.ok(Number.isInteger(faceCount), 'only complete triangles are emitted')
  assert.ok(faceCount >= minimumFaces && faceCount <= maximumFaces, `face count ${faceCount} stays in budget`)

  // SphereGeometry has unused duplicate vertices at its poles. Only rendered
  // vertices need a unit normal; the unused vertices still must remain finite.
  const referencedVertices = index ? new Set(index.array) : Array.from({ length: positions.count }, (_, i) => i)
  for (const i of referencedVertices) {
    assert.ok(i >= 0 && i < positions.count, 'indices reference existing vertices')
    const length = Math.hypot(normals.getX(i), normals.getY(i), normals.getZ(i))
    assert.ok(Math.abs(length - 1) < 0.001, `rendered normal ${i} is normalized`)
  }
  geometry.computeBoundingBox()
  geometry.computeBoundingSphere()
  assert.ok(Number.isFinite(geometry.boundingSphere.radius) && geometry.boundingSphere.radius > 0)
  return geometry.boundingBox.getSize(new THREE.Vector3())
}

function triangleGeometry({ color = false, indexed = false } = {}) {
  const geometry = new THREE.BufferGeometry()
  geometry.setAttribute('position', new THREE.Float32BufferAttribute([0, 0, 0, 1, 0, 0, 0, 1, 0], 3))
  if (color) geometry.setAttribute('color', new THREE.Float32BufferAttribute([1, 0, 0, 0, 1, 0, 0, 0, 1], 3))
  if (indexed) geometry.setIndex([0, 1, 2])
  return geometry
}

test('continuous cloud sculpt has finite smooth geometry and the flattened cloud proportions', (context) => {
  const geometry = sculptCloud()
  context.after(() => geometry.dispose())
  const size = assertHealthyGeometry(geometry, 10000, 18000)
  assert.ok(size.x > 2.08 && size.x < 2.22, `cloud width ${size.x}`)
  assert.ok(size.y > 1.70 && size.y < 1.84, `cloud height ${size.y} includes the 0.79 squash`)
  assert.ok(size.z > 1.32 && size.z < 1.44, `cloud depth ${size.z}`)
  assert.ok(size.y / size.x < 0.87, 'the silhouette remains wider than tall')
  assert.ok(geometry.boundingBox.min.y < -0.8 && geometry.boundingBox.max.y > 0.88)
})

test('both sculpted ears have finite normals, bounded face counts, colors, and mirrored bounds', (context) => {
  const left = sculptEar(-1), right = sculptEar(1)
  context.after(() => { left.dispose(); right.dispose() })
  for (const geometry of [left, right]) {
    const size = assertHealthyGeometry(geometry, 3000, 4500)
    assert.ok(size.x > 0.73 && size.x < 0.85)
    assert.ok(size.y > 0.62 && size.y < 0.7)
    assert.ok(size.z > 0.35 && size.z < 0.45)
    const colors = geometry.getAttribute('color')
    assertFiniteAttribute(colors, 'ear vertex colors')
    assert.equal(colors.count, geometry.getAttribute('position').count)
    for (const channel of colors.array) assert.ok(channel >= 0 && channel <= 1)
  }
  assert.ok(Math.abs(left.boundingBox.min.x + right.boundingBox.max.x) < 1e-6)
  assert.ok(Math.abs(left.boundingBox.max.x + right.boundingBox.min.x) < 1e-6)
  assert.ok(Math.abs(left.boundingBox.min.y - right.boundingBox.min.y) < 1e-6)
  assert.ok(Math.abs(left.boundingBox.max.z - right.boundingBox.max.z) < 1e-6)
})

test('fur is deterministic for a seed and varies with a different seed', (context) => {
  const source = new THREE.SphereGeometry(1, 16, 12)
  const options = { density: 256, length: 0.031, seed: 'momo-soft-coat' }
  const first = createFurSurface(source, options)
  const second = createFurSurface(source, options)
  const other = createFurSurface(source, { ...options, seed: 'another-coat' })
  context.after(() => { first.dispose(); second.dispose(); other.dispose(); source.dispose() })
  assert.equal(first.object.geometry.instanceCount, options.density)
  assert.equal(first.object.geometry.getIndex().count / 3, 8, 'eight triangles per ribbon')
  for (const attribute of ['strandRoot', 'strandNormal', 'strandTangent', 'strandColor', 'strandShape']) {
    const actual = first.object.geometry.getAttribute(attribute)
    assertFiniteAttribute(actual, attribute)
    assert.equal(actual.count, options.density)
    assert.deepEqual(actual.array, second.object.geometry.getAttribute(attribute).array)
  }
  assert.notDeepEqual(first.object.geometry.getAttribute('strandRoot').array, other.object.geometry.getAttribute('strandRoot').array)
  const roots = first.object.geometry.getAttribute('strandRoot')
  const normal = first.object.geometry.getAttribute('strandNormal')
  const tangent = first.object.geometry.getAttribute('strandTangent')
  const point = new THREE.Vector3()
  for (let i = 0; i < roots.count; i++) {
    point.fromBufferAttribute(roots, i)
    assert.ok(first.object.geometry.boundingBox.containsPoint(point))
    assert.ok(first.object.geometry.boundingSphere.containsPoint(point))
    assert.ok(Math.abs(Math.hypot(normal.getX(i), normal.getY(i), normal.getZ(i)) - 1) < 1e-6)
    const perpendicular = normal.getX(i) * tangent.getX(i) + normal.getY(i) * tangent.getY(i) + normal.getZ(i) * tangent.getZ(i)
    assert.ok(Math.abs(perpendicular) < 1e-6)
  }
})

test('fur interpolates source vertex colors barycentrically in linear RGB', (context) => {
  const source = triangleGeometry({ color: true, indexed: true })
  const fur = createFurSurface(source, { density: 64, vertexColors: true, seed: 17 })
  context.after(() => { fur.dispose(); source.dispose() })
  const roots = fur.object.geometry.getAttribute('strandRoot')
  const colors = fur.object.geometry.getAttribute('strandColor')
  const normals = fur.object.geometry.getAttribute('strandNormal')
  for (let i = 0; i < roots.count; i++) {
    const x = roots.getX(i), y = roots.getY(i)
    assert.ok(Math.abs(colors.getX(i) - (1 - x - y)) < 1e-6)
    assert.ok(Math.abs(colors.getY(i) - x) < 1e-6)
    assert.ok(Math.abs(colors.getZ(i) - y) < 1e-6)
    assert.equal(normals.getX(i), 0)
    assert.equal(normals.getY(i), 0)
    assert.equal(normals.getZ(i), 1, 'a missing source normal falls back to the triangle normal')
  }
})

test('fur uses the requested solid color unless source vertex colors are enabled', (context) => {
  const source = triangleGeometry({ color: true })
  const expected = new THREE.Color('#fff2dd')
  const fur = createFurSurface(source, { density: 16, color: '#fff2dd', vertexColors: false })
  context.after(() => { fur.dispose(); source.dispose() })
  const colors = fur.object.geometry.getAttribute('strandColor')
  for (let i = 0; i < colors.count; i++) {
    assert.ok(Math.abs(colors.getX(i) - expected.r) < 1e-6)
    assert.ok(Math.abs(colors.getY(i) - expected.g) < 1e-6)
    assert.ok(Math.abs(colors.getZ(i) - expected.b) < 1e-6)
  }
})

for (const indexed of [false, true]) {
  test(`fur respects a nonzero drawRange and skips degenerate faces (${indexed ? 'indexed' : 'non-indexed'})`, (context) => {
    const source = new THREE.BufferGeometry()
    source.setAttribute('position', new THREE.Float32BufferAttribute([
      -100, 0, 0, -99, 0, 0, -100, 1, 0,
      4, 0, 0, 5, 0, 0, 4, 1, 0,
      0, 0, 0, 0, 0, 0, 0, 0, 0,
      100, 0, 0, 101, 0, 0, 100, 1, 0,
    ], 3))
    if (indexed) source.setIndex(Array.from({ length: 12 }, (_, i) => i))
    source.setDrawRange(3, 6)
    const fur = createFurSurface(source, { density: 128, seed: 19 })
    context.after(() => { fur.dispose(); source.dispose() })
    const roots = fur.object.geometry.getAttribute('strandRoot')
    assert.equal(roots.count, 128)
    for (let i = 0; i < roots.count; i++) {
      const x = roots.getX(i), y = roots.getY(i)
      assert.ok(x >= 4 && x <= 5 && y >= 0 && y <= 1)
      assert.ok(x - 4 + y <= 1.000001)
      assert.equal(roots.getZ(i), 0)
    }
  })
}

test('degenerate sources and zero-density coats stay empty with finite bounds', (context) => {
  const degenerate = new THREE.BufferGeometry()
  degenerate.setAttribute('position', new THREE.Float32BufferAttribute(new Float32Array(9), 3))
  const triangle = triangleGeometry()
  const coats = [createFurSurface(degenerate), createFurSurface(triangle, { density: 0 })]
  context.after(() => { coats.forEach((coat) => coat.dispose()); degenerate.dispose(); triangle.dispose() })
  for (const coat of coats) {
    assert.equal(coat.object.geometry.instanceCount, 0)
    assert.equal(coat.object.geometry.boundingSphere.radius, 0)
    assert.deepEqual(coat.object.geometry.boundingSphere.center.toArray(), [0, 0, 0])
  }
})

test('fur warmth is bounded and disposal is idempotent without owning the source', (context) => {
  const source = triangleGeometry()
  context.after(() => source.dispose())
  const parent = new THREE.Group()
  const coat = createFurSurface(source, { density: 4 })
  parent.add(coat.object)
  let geometryDisposals = 0, materialDisposals = 0, sourceDisposals = 0
  coat.object.geometry.addEventListener('dispose', () => geometryDisposals++)
  coat.object.material.addEventListener('dispose', () => materialDisposals++)
  source.addEventListener('dispose', () => sourceDisposals++)
  for (const [value, expected] of [[true, 1], [false, 0], [0.4, 0.4], [10, 1], [-4, 0], [NaN, 0]]) {
    coat.setWarmth(value)
    assert.equal(coat.object.material.uniforms.warmth.value, expected)
  }
  coat.dispose()
  coat.dispose()
  assert.equal(coat.object.parent, null)
  assert.equal(parent.children.length, 0)
  assert.equal(geometryDisposals, 1)
  assert.equal(materialDisposals, 1)
  assert.equal(sourceDisposals, 0)
})
