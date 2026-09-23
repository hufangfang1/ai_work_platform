import test from 'node:test'
import assert from 'node:assert/strict'
import {readFileSync} from 'node:fs'
import * as T from 'three'
import {housePlacement} from '../src/scene/homeLayout.js'
const fit=JSON.parse(readFileSync(new URL('../src/scene/photoCameraFit.json',import.meta.url)))
test('fitted reference camera reproduces recorded pixels through the Three.js world transform',()=>{
 const camera=new T.PerspectiveCamera(fit.verticalFovDegrees,4/3,.08,130),c=fit.cameraLocalScaled,r=fit.worldToCameraCV;
 camera.position.set(housePlacement.position[0]-c[0],c[1],housePlacement.position[2]-.15-c[2]);
 const cv=new T.Matrix4().set(...r[0],0,...r[1],0,...r[2],0,0,0,0,1).transpose();
 camera.quaternion.setFromRotationMatrix(new T.Matrix4().makeRotationY(Math.PI).multiply(cv).multiply(new T.Matrix4().makeRotationX(Math.PI)));
 camera.updateMatrixWorld();
 for(const mark of fit.landmarks){
  const [x,y]=mark.local,p=new T.Vector3(housePlacement.position[0]-x*housePlacement.scaleX,y,housePlacement.position[2]-.15).project(camera);
  assert.ok(Math.abs((p.x+1)*720-mark.fit[0])<1e-5);
  assert.ok(Math.abs((1-p.y)*540-mark.fit[1])<1e-5);
 }
});
