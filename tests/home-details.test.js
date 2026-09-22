import test from 'node:test'
import assert from 'node:assert/strict'
import {branchGeometry,carBodyGeometry,corrugatedSheetGeometry,wornSkirtingGeometry} from '../src/scene/homeDetailGeometry.js'

test('detail geometry has finite positions, normals and bounded sizes',()=>{
  for(const geometry of [branchGeometry(3,.25,1),carBodyGeometry()]){
    for(const name of ['position','normal'])assert.ok([...geometry.attributes[name].array].every(Number.isFinite));
    geometry.computeBoundingBox();const b=geometry.boundingBox;
    assert.ok(b.max.x-b.min.x<4);assert.ok(b.max.y-b.min.y<4);assert.ok(b.max.z-b.min.z<4);geometry.dispose();
  }
});
test('corrugated shelter preserves footprint, has upward normals and real height variation',()=>{
  const sheet=corrugatedSheetGeometry(3.75,6.6);sheet.computeBoundingBox();const b=sheet.boundingBox;
  assert.ok(Math.abs(b.max.x-b.min.x-3.75)<1e-5);assert.ok(Math.abs(b.max.z-b.min.z-6.6)<1e-5);
  assert.ok(b.max.y-b.min.y>.05&&b.max.y-b.min.y<.15);
  for(const v of sheet.attributes.position.array)assert.ok(Number.isFinite(v));
  for(let i=0;i<sheet.attributes.normal.count;i++)assert.ok(sheet.attributes.normal.getY(i)>0);
  sheet.dispose();
});
test('chipped wall skirting keeps floor sealed and stays below window sills',()=>{
  const band=wornSkirtingGeometry(12,.44,3);band.computeBoundingBox();assert.ok(band.boundingBox.min.y===0);assert.ok(band.boundingBox.max.y<.51);
  for(const v of band.attributes.normal.array)assert.ok(Number.isFinite(v));
  for(let i=0;i<band.attributes.normal.count;i++)assert.ok(band.attributes.normal.getZ(i)>.99);
  band.dispose();
});
test('tree branches bend and car geometry stays inside the existing collision footprint',()=>{
  const tree=branchGeometry(3,.25,0);tree.computeBoundingBox();assert.ok(tree.boundingBox.max.x>.25);tree.dispose();
  const car=carBodyGeometry();car.computeBoundingBox();assert.ok(car.boundingBox.min.x>=-.85&&car.boundingBox.max.x<=.85);assert.ok(car.boundingBox.min.z>=-1.4&&car.boundingBox.max.z<=1.45);car.dispose();
});
