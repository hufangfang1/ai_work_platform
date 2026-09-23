import test from 'node:test'
import assert from 'node:assert/strict'
import {createGroundCracks,createWallFootWear} from '../src/scene/homeGroundDetail.js'
import {westBoundary} from '../src/scene/homeLayout.js'

test('cracks are flat, upward-facing and fade to transparent edges',()=>{
  const geometry=createGroundCracks(),p=geometry.attributes.position,c=geometry.attributes.color,n=geometry.attributes.normal;
  assert.equal(c.itemSize,4);
  for(let i=0;i<p.count;i++){
    assert.ok(Math.abs(p.getY(i)-.004)<1e-6);
    assert.ok(n.getY(i)>.99);
    assert.ok(c.getW(i)>=0&&c.getW(i)<=.71);
    if(i%4===0||i%4===3)assert.equal(c.getW(i),0);
  }
  assert.ok(geometry.index.count/3<1000);
  const same=createGroundCracks(),other=createGroundCracks(20);
  assert.deepEqual(p.array,same.attributes.position.array);
  assert.notDeepEqual(p.array,other.attributes.position.array);
  [geometry,same,other].forEach(g=>g.dispose());
});

test('wall foot stains stay within 31cm of confirmed wall faces',()=>{
  const geometry=createWallFootWear(),p=geometry.attributes.position,n=geometry.attributes.normal,c=geometry.attributes.color;
  for(let i=0;i<p.count;i++){
    const x=p.getX(i);
    assert.ok(Math.min(...[-6.1,westBoundary.centerX-westBoundary.thickness/2-.035,5.97].map(w=>Math.abs(x-w)))<=.301);
    assert.ok(n.getY(i)>.99);
    if(i%2===1)assert.equal(c.getW(i),0);
  }
  geometry.dispose();
});
