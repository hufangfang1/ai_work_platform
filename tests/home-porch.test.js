import { test } from 'node:test'
import assert from 'node:assert/strict'
import * as T from 'three'
import { createPorchGeometry, createRiserGeometry, createBrokenStepGeometry, createPorchRampGeometry } from '../src/scene/homePorch.js'

function checkTriangles(geometry) {
  const position = geometry.attributes.position, index = geometry.index
  const a = new T.Vector3(), b = new T.Vector3(), c = new T.Vector3(), cross = new T.Vector3()
  let volume = 0
  for (let i = 0; i < index.count; i += 3) {
    a.fromBufferAttribute(position, index.getX(i)); b.fromBufferAttribute(position, index.getX(i + 1)); c.fromBufferAttribute(position, index.getX(i + 2))
    assert.ok(cross.subVectors(b, a).cross(new T.Vector3().subVectors(c, a)).lengthSq() > 1e-15, `non-degenerate face ${i / 3}`)
    volume += a.dot(cross.crossVectors(b, c)) / 6
  }
  assert.ok(volume > 0, 'outward face winding gives positive volume')
  for (const name of ['position', 'normal', 'uv', 'color']) assert.ok([...geometry.attributes[name].array].every(Number.isFinite), `${name} is finite`)
  return volume
}

test('the worn porch keeps the confirmed footprint and a shallow, level walking surface', () => {
  const geometry = createPorchGeometry(), bounds = geometry.boundingBox
  assert.ok(Math.abs(bounds.min.x + 7.65) < 1e-6)
  assert.ok(Math.abs(bounds.max.x - 7.65) < 1e-6)
  assert.ok(Math.abs(bounds.min.z + .9) < 1e-6)
  assert.ok(Math.abs(bounds.max.z - .9) < 1e-6)
  assert.equal(bounds.min.y, 0)
  assert.ok(bounds.max.y <= .281 && bounds.max.y > .276)
  const volume = checkTriangles(geometry)
  assert.ok(volume > 15.3 * 1.8 * .28 * .96)
  geometry.dispose()
})

test('porch end caps and edge bevels form a closed solid after welding coincident normals seams', () => {
  const geometry = createPorchGeometry(3, 1.8, .28)
  const position = geometry.attributes.position, edges = new Map(), key = index => [position.getX(index), position.getY(index), position.getZ(index)].map(n => n.toFixed(6)).join(',')
  for (let i = 0; i < geometry.index.count; i += 3) {
    const points = [0, 1, 2].map(j => key(geometry.index.getX(i + j)))
    for (let j = 0; j < 3; j++) {
      const edge = [points[j], points[(j + 1) % 3]].sort().join('|')
      edges.set(edge, (edges.get(edge) || 0) + 1)
    }
  }
  assert.ok([...edges.values()].every(count => count === 2), 'every welded edge is shared by exactly two triangles')
  geometry.dispose()
})

test('porch wear and merged brickwork are deterministic, varied and bounded', () => {
  const first = createPorchGeometry(), second = createPorchGeometry(), other = createPorchGeometry(15.3, 1.8, .28, 2000)
  assert.deepEqual(first.attributes.position.array, second.attributes.position.array)
  assert.notDeepEqual(first.attributes.position.array, other.attributes.position.array)
  const bricks = createRiserGeometry(), twin = createRiserGeometry()
  assert.deepEqual(bricks.attributes.position.array, twin.attributes.position.array)
  assert.ok(bricks.boundingBox.max.y < .24)
  assert.ok(Math.abs(bricks.boundingBox.min.x + 7.65) < 1e-6)
  assert.ok(Math.abs(bricks.boundingBox.max.x - 7.65) < 1e-6)
  assert.ok(new Set([...bricks.attributes.color.array].map(n => n.toFixed(3))).size > 30)
  checkTriangles(bricks)
  for (const geometry of [first, second, other, bricks, twin]) geometry.dispose()
})

test('porch geometry also supports the shallow drainage lip without invalid faces', () => {
  checkTriangles(createPorchGeometry(15.3, .16, .055, 47))
  assert.throws(() => createPorchGeometry(0, 1, .2), RangeError)
  assert.throws(() => createRiserGeometry(15, 0), RangeError)
})

test('broken lower step stays below the porch and retains a solid, usable tread',()=>{
  const geometry=createBrokenStepGeometry(15.3,.4,.085);
  checkTriangles(geometry);
  assert.equal(geometry.boundingBox.min.y,0);
  assert.ok(geometry.boundingBox.max.y<=.086);
  assert.ok(geometry.boundingBox.max.z<=.2);
  assert.ok(geometry.boundingBox.max.z-geometry.boundingBox.min.z>.33);
  geometry.dispose();
});

test('porch ramp is a solid slope from platform height down to a shallow yard lip',()=>{
  const geometry=createPorchRampGeometry(.65,.95,.28);
  checkTriangles(geometry);
  const p=geometry.attributes.position;
  let rear=0,foot=0;
  for(let i=0;i<p.count;i++){
    if(p.getZ(i)<-.47)rear=Math.max(rear,p.getY(i));
    if(p.getZ(i)>.47)foot=Math.max(foot,p.getY(i));
  }
  assert.ok(rear>.275&&rear<=.281,'rear joins the veranda');
  assert.ok(foot>0&&foot<.012,'foot is near flush with courtyard, not a box end');
  assert.equal(geometry.boundingBox.min.y,0);
  geometry.dispose();
});
