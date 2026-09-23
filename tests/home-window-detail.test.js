import test from 'node:test'
import assert from 'node:assert/strict'
import {createGlazingGeometry,createCurtainGeometry,createPaperGeometry} from '../src/scene/homeWindowDetail.js'

test('window detail remains finite, front-facing and only millimetres deep',()=>{
  for(const g of [createGlazingGeometry(.8,1.45,2),createCurtainGeometry(3.06,2.25,1),createPaperGeometry(.5,.6)]){
    for(const a of Object.values(g.attributes))assert.ok(a.array.every(Number.isFinite));
    const p=g.attributes.position,n=g.attributes.normal;
    for(let i=0;i<p.count;i++){
      assert.ok(Math.abs(p.getZ(i))<.006);
      assert.ok(n.getZ(i)>.97);
    }
    g.dispose();
  }
});
test('glass dimensions and feathered dirt do not cover its wood frame',()=>{
  const g=createGlazingGeometry(.8,1.45,2);g.computeBoundingBox();
  const {min,max}=g.boundingBox;
  assert.ok(Math.abs(max.x-min.x-.8)<1e-6&&Math.abs(max.y-min.y-1.45)<1e-6);
  const c=g.attributes.color;assert.equal(c.itemSize,4);
  for(let i=0;i<c.count;i++)assert.ok(c.getW(i)>=.64&&c.getW(i)<=1);
  assert.throws(()=>createGlazingGeometry(0,1),RangeError);g.dispose();
});
