import { test } from 'node:test'
import assert from 'node:assert/strict'
import { Mesh, MeshBasicMaterial, Raycaster, Vector3 } from 'three'
import { createFacadeGeometry } from '../src/scene/homeFacade.js'
import { houseDoorLocalX, houseEntry, houseLocalMaxX, houseLocalMinX, houseWindows, houseWindowCenterY, houseWindowOpeningHeight } from '../src/scene/homeLayout.js'

const options = { minX: houseLocalMinX, maxX: houseLocalMaxX, doorX: houseDoorLocalX, windows: houseWindows }
const near = (actual, expected) => assert.ok(Math.abs(actual - expected) < 1e-6, `${actual} ≈ ${expected}`)

function withFacade(run) {
  const geometry = createFacadeGeometry(options)
  const material = new MeshBasicMaterial()
  const facade = new Mesh(geometry, material)
  facade.updateMatrixWorld(true)
  const raycast = (origin, direction = [0, 0, -1]) => new Raycaster(new Vector3(...origin), new Vector3(...direction)).intersectObject(facade)
  try { run({ geometry, raycast }) } finally { geometry.dispose(); material.dispose() }
}

test('facade preserves the approved bounds, with finite unit normals and 4 m UVs', () => {
  withFacade(({ geometry }) => {
    const { min, max } = geometry.boundingBox
    near(min.x, options.minX); near(max.x, options.maxX)
    near(min.y, 0); near(max.y, 3.8)
    near(min.z, -.15); near(max.z, .15)
    const p = geometry.getAttribute('position'), n = geometry.getAttribute('normal'), uv = geometry.getAttribute('uv')
    for (const attribute of [p, n, uv]) assert.ok([...attribute.array].every(Number.isFinite))
    for (let i = 0; i < p.count; i++) {
      near(Math.hypot(n.getX(i), n.getY(i), n.getZ(i)), 1)
      if (Math.abs(n.getZ(i)) > .5) {
        near(uv.getX(i), p.getX(i) / 4); near(uv.getY(i), p.getY(i) / 4)
      } else if (Math.abs(n.getX(i)) > .5) {
        near(uv.getX(i), p.getZ(i) / 4); near(uv.getY(i), p.getY(i) / 4)
      } else {
        near(uv.getX(i), p.getX(i) / 4); near(uv.getY(i), p.getZ(i) / 4)
      }
    }
  })
})

test('window and bottom-open door centers are empty while adjacent plaster stays solid', () => {
  withFacade(({ raycast }) => {
    assert.equal(houseWindows.length,2)
    near(houseWindows[0].width,houseWindows[1].width)
    near(houseWindows[0].width,2.8)
    near(houseWindowCenterY-houseWindowOpeningHeight/2,1.715)
    near(houseWindowCenterY+houseWindowOpeningHeight/2,3.715)
    for (const window of houseWindows) {
      assert.equal(raycast([window.x, houseWindowCenterY, 1]).length, 0)
      assert.equal(raycast([window.x, houseWindowCenterY, -1], [0, 0, 1]).length, 0)
      assert.equal(raycast([window.x, 3.6, 1]).length, 0)
      assert.ok(raycast([window.x, 1.5, 1]).length > 0)
      assert.ok(raycast([window.x, 1.1, 1]).length > 0)
      assert.ok(raycast([window.x, 3.77, 1]).length > 0)
      assert.ok(raycast([window.x+1.55, houseWindowCenterY, 1]).length > 0)
      assert.ok(raycast([window.x + (window.width + .08) / 2 + .1, houseWindowCenterY, 1]).length > 0)
    }
    for (const y of [.001, .8, 2.27, 3.74]) assert.equal(raycast([houseDoorLocalX, y, 1]).length, 0)
    assert.ok(raycast([houseDoorLocalX, 3.78, 1]).length > 0)
    assert.ok(raycast([houseDoorLocalX - houseEntry.openingWidth/2 - .1, 2.27, 1]).length > 0)
    near(raycast([houseDoorLocalX, -.1, 0], [0, 1, 0])[0].point.y, Math.fround(3.76))
  })
})

test('wider double door and sidelights fit inside the expanded facade opening',()=>{
  near(houseEntry.woodWidth,2.15)
  near(houseEntry.sideWindowWidth,.5)
  near(houseEntry.transomWidth,3.24)
  near(houseEntry.openingWidth,3.33)
  near((houseEntry.woodWidth-1.9)*.8,.2)
  near((houseEntry.sideWindowWidth-.25)*.8,.2)
  near(houseEntry.transomWidth/2,houseEntry.sideWindowOffset+houseEntry.sideWindowWidth/2)
  assert.ok(houseEntry.sideWindowOffset-houseEntry.sideWindowWidth/2>houseEntry.woodWidth/2)
  assert.ok(houseEntry.sideWindowOffset+houseEntry.sideWindowWidth/2<houseEntry.openingWidth/2)
})

test('front, rear and recessed jambs face out of the solid wall', () => {
  withFacade(({ raycast }) => {
    const window = houseWindows[0]
    near(raycast([window.x, .8, 1])[0].face.normal.z, 1)
    near(raycast([window.x, .8, -1], [0, 0, 1])[0].face.normal.z, -1)
    const leftJamb = raycast([window.x, houseWindowCenterY, 0], [-1, 0, 0])[0]
    const rightJamb = raycast([window.x, houseWindowCenterY, 0], [1, 0, 0])[0]
    near(leftJamb.point.x, window.x - (window.width + .08) / 2)
    near(rightJamb.point.x, window.x + (window.width + .08) / 2)
    near(leftJamb.face.normal.x, 1)
    near(rightJamb.face.normal.x, -1)
    near(raycast([window.x, houseWindowCenterY, 0], [0, -1, 0])[0].face.normal.y, 1)
    near(raycast([window.x, houseWindowCenterY, 0], [0, 1, 0])[0].face.normal.y, -1)
  })
})

test('invalid or overlapping openings are rejected instead of creating broken triangles', () => {
  assert.throws(() => createFacadeGeometry({ ...options, minX: NaN }), TypeError)
  assert.throws(() => createFacadeGeometry({ ...options, depth: 0 }), RangeError)
  assert.throws(() => createFacadeGeometry({ ...options, height: 3.7 }), RangeError)
  assert.throws(() => createFacadeGeometry({ ...options, windows: [{ x: houseDoorLocalX, width: 1 }] }), RangeError)
  assert.throws(() => createFacadeGeometry({ ...options, windows: [{ x: -3, width: 2 }, { x: -4, width: 2 }] }), RangeError)
  assert.throws(() => createFacadeGeometry({ ...options, windows: [{ x: -3, width: -1 }] }), TypeError)
})
