import test from 'node:test'
import assert from 'node:assert/strict'
import { QuadraticBezierCurve3, Vector3 } from 'three'
import {houseClothesline as line,houseDoorLocalX,houseEntry} from '../src/scene/homeLayout.js'

test('red clothesline ends at the sidelight bar and stays outside the doorway',()=>{
  assert.equal(line.end[0],houseDoorLocalX-houseEntry.sideWindowOffset);
  const curve=new QuadraticBezierCurve3(...[line.start,line.control,line.end].map(p=>new Vector3(...p)));
  const doorLeft=houseDoorLocalX-houseEntry.woodWidth/2;
  for(const point of curve.getPoints(100))assert.ok(point.x+.006<doorLeft);
  const t=(line.garmentX-line.start[0])/(line.end[0]-line.start[0]);
  assert.ok(t>0&&t<1);
  assert.ok(Math.abs(curve.getPoint(t).x-line.garmentX)<1e-10);
});
