import test from 'node:test'
import assert from 'node:assert/strict'
import {createYardSoilGeometry} from '../src/scene/homeGroundRegions.js'
import {yardGroundBoundary,courtyardTrees,houseWestX,gateZ,shedPlacement} from '../src/scene/homeLayout.js'

const frontZ=gateZ-1.7,backZ=8,minX=-6.3,maxX=houseWestX
const boundaryAt=z=>{
  const i=yardGroundBoundary.findIndex(([bz])=>bz>=z)
  if(i<=0)return yardGroundBoundary[0][1]
  const [z0,x0]=yardGroundBoundary[i-1],[z1,x1]=yardGroundBoundary[i]
  return x0+(z-z0)/(z1-z0)*(x1-x0)
}

test('marked line leaves the shelter on earth and the entrance on cement',()=>{
  assert.equal(yardGroundBoundary[0][0],frontZ)
  assert.equal(yardGroundBoundary.at(-1)[0],backZ)
  assert.ok(shedPlacement.car[0]<boundaryAt(shedPlacement.car[2]))
  assert.ok(shedPlacement.trike[0]<boundaryAt(shedPlacement.trike[2]))
  assert.ok(-5.35<boundaryAt(-2.4),'tree on the east side stands on earth')
  assert.ok(3.7>boundaryAt(gateZ),'gate-side paving remains cement')
  assert.ok(0>boundaryAt(6),'main doorway paving remains cement')
  assert.ok(-1.5>boundaryAt(3),'the marked wedge beside the step is also cement')
  assert.ok(-3.3<boundaryAt(4),'the parked car remains on the earth side')
  assert.ok(boundaryAt(-5)>boundaryAt(5),'the edge angles toward the house as it goes back')
})

test('small tree occupies the circled earth patch instead of standing by the wall',()=>{
  const [large,small]=courtyardTrees
  assert.deepEqual([large.x,large.z,large.size],[-5.35,-2.4,1.1])
  assert.deepEqual([small.x,small.z,small.size],[-2.1,-.5,.85])
  assert.ok(small.x<boundaryAt(small.z),'the marked position is on earth')
  assert.ok(small.x-large.x>3,'the small tree is clear of the big tree')
  assert.ok(Math.hypot(small.x-shedPlacement.trike[0],small.z-shedPlacement.trike[2])>3,'the trunk stays clear of the trike')
})

test('soil strip follows the line with upward normals and AO matched to the full yard',()=>{
  const geometry=createYardSoilGeometry(yardGroundBoundary,{frontZ,backZ,minX,maxX})
  const position=geometry.getAttribute('position'),uv=geometry.getAttribute('uv'),ao=geometry.getAttribute('uv1'),normal=geometry.getAttribute('normal')
  assert.equal(position.count,yardGroundBoundary.length*2)
  assert.equal(geometry.index.count,(yardGroundBoundary.length-1)*6)
  for(let i=0;i<position.count;i++){
    assert.ok(Math.abs(position.getY(i)-.003)<1e-6)
    assert.ok(normal.getY(i)>.999)
    assert.ok(Math.abs(uv.getX(i)-ao.getX(i))<1e-6)
    assert.ok(Math.abs(uv.getY(i)-ao.getY(i))<1e-6)
  }
  assert.ok(Math.abs(ao.getY(position.count-1))<1e-6,'back edge samples the back of the AO texture')
  assert.ok(Math.abs(position.getZ(0)-frontZ)<1e-6)
  assert.ok(Math.abs(position.getX(1)-yardGroundBoundary[0][1])<1e-6)
  assert.ok(Math.abs(geometry.boundingBox.max.z-backZ)<1e-6)
  geometry.dispose()
})

test('soil boundary rejects malformed or out-of-yard lines',()=>{
  assert.throws(()=>createYardSoilGeometry([[0,0]],{frontZ,backZ,minX,maxX}),RangeError)
  assert.throws(()=>createYardSoilGeometry([[frontZ,0],[frontZ,1],[backZ,0]],{frontZ,backZ,minX,maxX}),RangeError)
  assert.throws(()=>createYardSoilGeometry([[frontZ,minX],[backZ,0]],{frontZ,backZ,minX,maxX}),RangeError)
})
