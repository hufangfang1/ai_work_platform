import test from 'node:test'
import assert from 'node:assert/strict'
import * as T from 'three'
import {rayBoxDistance,skyVisibility,bakeGroundOcclusion,courtyardOccluders,gateLeafOccluders} from '../src/scene/homeOcclusion.js'
import {houseWestX,gateX,gateZ,gatePortico,gateEastPillarX,gateWestPillarX} from '../src/scene/homeLayout.js'

test('AO rays handle parallel directions, distant blockers and hits behind the surface',()=>{
  assert.equal(rayBoxDistance([0,0,0],[0,1,0],[-1,2,-1,1,3,1]),2);
  assert.equal(rayBoxDistance([2,0,0],[0,1,0],[-1,2,-1,1,3,1]),Infinity);
  assert.equal(rayBoxDistance([0,4,0],[0,1,0],[-1,2,-1,1,3,1]),Infinity);
  assert.equal(rayBoxDistance([0,0,0],[0,1,0],[-1,8,-1,1,9,1]),Infinity);
});
test('open yard receives more sky than under the fixed shelter',()=>{
  const blockers=courtyardOccluders(),open=skyVisibility([1,.025,-1],blockers),covered=skyVisibility([-4.8,.025,4],blockers);
  assert.ok(open>covered+.12);assert.ok(covered>=.2&&open<=1);
  assert.equal(skyVisibility([0,0,0],[]),1);
});
test('baked occlusion is deterministic linear data with a separate UV channel',()=>{
  const a=bakeGroundOcclusion([-6.3,houseWestX,gateZ-1.7,8],undefined,16),b=bakeGroundOcclusion([-6.3,houseWestX,gateZ-1.7,8],undefined,16);
  assert.deepEqual(a.image.data,b.image.data);assert.equal(a.colorSpace,T.NoColorSpace);assert.equal(a.channel,1);
  assert.ok(a.image.data.every(v=>v>=51&&v<=255));a.dispose();b.dispose();
});
test('both recessed gate leaves cast bounded contact shade',()=>{
  const slabs=gateLeafOccluders();assert.equal(slabs.length,16);
  assert.ok(slabs.every(b=>b[0]>gateEastPillarX&&b[3]<gateWestPillarX&&b[2]>gatePortico.frontZ&&b[5]<gatePortico.backZ));
  const leaf=skyVisibility([gateX+gatePortico.innerClearWidth/4,.083,gateZ+gatePortico.leafLocalZ],slabs)
  const away=skyVisibility([gateX+gatePortico.clearWidth,.083,gateZ+gatePortico.leafLocalZ],slabs)
  assert.ok(away>leaf+.2,`${away} away versus ${leaf} beneath the leaf`)
  const boxes=courtyardOccluders()
  assert.ok(boxes.length>slabs.length)
  const low=bakeGroundOcclusion([0,1,0,1],[[0,.1,0,1,.2,1]],8,.05);
  const high=bakeGroundOcclusion([0,1,0,1],[[0,.1,0,1,.2,1]],8,.3);
  assert.ok(high.image.data.every(v=>v===255));assert.ok(low.image.data.some(v=>v<200));
  low.dispose();high.dispose();
});
