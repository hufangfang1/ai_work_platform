import test from 'node:test'
import assert from 'node:assert/strict'
import {createIronGatePanel,setGroundOcclusionUV} from '../src/scene/homeGate.js'
import {gateX,gateZ,gateApron,gatePortico,gateEastPillarX,gateWestPillarX,westWingYardWallX} from '../src/scene/homeLayout.js'
import {createPorchGeometry} from '../src/scene/homePorch.js'

test('iron gate keeps its existing silhouette with millimetre-scale bowing',()=>{
  const g=createIronGatePanel(),{min,max}=g.boundingBox;
  assert.ok(Math.abs(min.x+.58)<1e-6&&Math.abs(max.x-.58)<1e-6);
  assert.ok(Math.abs(min.y)<1e-6&&Math.abs(max.y-2.925)<1e-6);
  assert.ok(min.z>=-.027&&max.z<=.027);
  for(const name of ['position','normal','color','uv'])assert.ok(g.attributes[name].array.every(Number.isFinite));
  const n=g.attributes.normal;
  for(let i=0;i<n.count;i++)assert.ok(Math.abs(Math.hypot(n.getX(i),n.getY(i),n.getZ(i))-1)<1e-5);
  assert.throws(()=>createIronGatePanel(0),RangeError);g.dispose();
});

test('flush gate apron AO coordinates are normalized without altering color UVs',()=>{
  const g=createPorchGeometry(gateApron.width,gateApron.depth,.065),oldUV=g.attributes.uv.array.slice();
  setGroundOcclusionUV(g,[gateX-gateApron.width/2,gateX+gateApron.width/2,gateApron.frontZ,gateApron.backZ],[gateX,0,gateApron.centerZ]);
  assert.deepEqual(g.attributes.uv.array,oldUV);
  const uv=g.attributes.uv1,p=g.attributes.position;
  for(let i=0;i<uv.count;i++){
    assert.ok(uv.getX(i)>=-1e-6&&uv.getX(i)<=1.000001);
    assert.ok(uv.getY(i)>=-1e-6&&uv.getY(i)<=1.000001);
    assert.ok(Math.abs(uv.getY(i)-(gateApron.depth/2-p.getZ(i))/gateApron.depth)<1e-6);
  }
  g.dispose();
});

test('narrow outer pillars extend one metre into the yard without moving the double gate',()=>{
  assert.equal(gateEastPillarX,1.77)
  assert.equal(gateWestPillarX,westWingYardWallX-.5)
  assert.ok(Math.abs(gateX-3.685)<1e-9)
  assert.ok(Math.abs(gatePortico.clearWidth-3.41)<1e-9)
  assert.equal(gatePortico.recessDepth,1)
  assert.ok(Math.abs(gatePortico.leafLocalZ-(gatePortico.frontZ-gateZ)-gatePortico.recessDepth)<1e-9)
  assert.ok(Math.abs(gatePortico.frontZ-(gateZ-.175))<1e-9)
  assert.ok(Math.abs(gatePortico.backZ-(gateZ+3.1))<1e-9)
  assert.ok(Math.abs(gatePortico.leafLocalZ-.825)<1e-9)
  assert.ok(Math.abs(gatePortico.depth-(gatePortico.backZ-gatePortico.frontZ))<1e-9)
  assert.ok(Math.abs(gateApron.backZ-gatePortico.backZ-.4)<1e-9)
  assert.equal(gatePortico.clearWidth-gatePortico.innerClearWidth,1)
  assert.equal(3.605-gatePortico.innerTopY,1)
  assert.ok(gatePortico.leafWidth>1.1)
  assert.ok(Math.abs(gatePortico.innerClearWidth-2*gatePortico.leafWidth-.03)<1e-9)
  const leaf=createIronGatePanel(gatePortico.leafWidth)
  assert.ok(Math.abs(leaf.boundingBox.max.x-leaf.boundingBox.min.x-gatePortico.leafWidth)<1e-6)
  leaf.dispose()
})
