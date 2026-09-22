import {test} from 'node:test'
import assert from 'node:assert/strict'
import {westFacade} from '../src/scene/homeLayout.js'
test('west door 1 replaces the marked window and only the enlarged adjacent window remains',()=>{
  assert.deepEqual(westFacade.doors.map(d=>d.z),[5.28,6.82])
  assert.equal(westFacade.windows.length,1)
  assert.equal(westFacade.windows[0].z,3.32)
  assert.ok(westFacade.windows[0].width>.85)
  assert.ok(westFacade.windows[0].height>1.25)
})
import {canWalk,moveWithinYard,homePhotos,housePlacement,shedPlacement,houseSideX,houseLocalMaxX,houseLocalMinX,houseWestX,eastWall,addedRooms,obstacles,gateX,houseDoorLocalX,houseWindows} from '../src/scene/homeLayout.js'
test('gate moves west, house door east and only one window remains under the narrower shelter',()=>{
  assert.ok(gateX>1.4)
  assert.ok(housePlacement.position[0]-houseDoorLocalX*housePlacement.scaleX<1.4)
  assert.ok(shedPlacement.width<5.85)
  const covered=houseWindows.filter(w=>Math.abs(housePlacement.position[0]-w.x*housePlacement.scaleX-shedPlacement.position[0])<shedPlacement.width/2)
  assert.equal(covered.length,1)
  assert.equal(canWalk(1.4,-8),false)
})
test('house side is flush with boundary and western rear gap contains rooms',()=>{
  assert.equal(houseSideX,eastWall.centerX-eastWall.thickness/2)
  assert.ok(Math.abs(housePlacement.position[0]-houseLocalMaxX*housePlacement.scaleX-houseSideX)<1e-9)
  assert.ok(Math.abs(housePlacement.position[0]-houseLocalMinX*housePlacement.scaleX-houseWestX)<1e-9)
  assert.equal(obstacles[0][1],addedRooms[1].bounds[1])
  assert.ok(obstacles[0][2]<=addedRooms[1].bounds[3])
  assert.equal(canWalk(7.5,10),false)
})
test('shelter overlaps the window while the house entrance remains accessible',()=>{
  const windowX=housePlacement.position[0]-7.4*housePlacement.scaleX
  assert.ok(Math.abs(shedPlacement.position[0]-windowX)<shedPlacement.width/2)
  assert.ok(Math.abs(shedPlacement.position[2]+shedPlacement.depth/2-(housePlacement.position[2]-.2))<.01)
  assert.ok(Math.abs(shedPlacement.position[0]-shedPlacement.width/2-(-6.15))<.01)
  assert.equal(canWalk(-1.8,5.9),true)
  assert.equal(canWalk(-4.7,6),true)
  assert.equal(canWalk(1.4,6.8),true)
  assert.equal(canWalk(-3.3,3.6),false)
  assert.equal(canWalk(-5.65,6.65),false)
  assert.equal(canWalk(-2.55,5.6),false)
})
test('the house faces the gate and the former house site is open courtyard',()=>{
  assert.ok(Math.abs(Math.cos(housePlacement.rotationY)+1)<1e-10)
  assert.equal(housePlacement.position[0],1.4)
  assert.ok(housePlacement.position[2]>7)
  assert.equal(canWalk(5,0),true)
  assert.equal(canWalk(1.4,7.5),false)
  assert.equal(canWalk(1.4,6),true)
})
test('all photo viewpoints are inside the traversable yard',()=>{
  for(const photo of homePhotos)assert.ok(canWalk(photo.position[0],photo.position[2]),photo.name)
})
test('walls, vehicles and unobserved interiors are not traversable',()=>{
  assert.equal(canWalk(6,1),false)
  assert.equal(canWalk(-3.8,2.5),false)
  assert.equal(canWalk(-4,-7.5),false)
  assert.equal(canWalk(-7,0),false)
})
test('gate passage can be entered but unmodelled outside is bounded',()=>{
  assert.equal(canWalk(gateX,-8),true)
  assert.equal(canWalk(gateX,-10),false)
})
test('large movement steps do not tunnel through a parked vehicle',()=>{
  const result=moveWithinYard({x:0,z:3.6},-6,0)
  assert.ok(result.x>-2.45)
  assert.ok(canWalk(result.x,result.z))
})
test('wall sliding preserves valid forward motion',()=>{
  const result=moveWithinYard({x:9.1,z:-3},2,1)
  assert.ok(result.x<9.2)
  assert.ok(result.z>-2.1)
})
test('annotated rooms are solid and west boundary leaves the former wall open',()=>{
  assert.equal(canWalk(-4.7,9),false)
  assert.equal(canWalk(7.5,2),false)
  assert.equal(canWalk(7.5,6),false)
  assert.equal(canWalk(6.3,-2),true)
  assert.equal(canWalk(9.6,-2),false)
})
