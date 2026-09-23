import test from 'node:test'
import assert from 'node:assert/strict'
import * as T from 'three'
import {photoHomography,photoSources,photoRegions,attachPhotoSurface} from '../src/scene/homePhotoProjection.js'

test('projective photo mapping meets all four perspective-distorted corners',()=>{
  const corners=[[120,80],[910,170],[800,730],[200,660]],h=photoHomography(corners,1000,800);
  const uv=[[0,1],[1,1],[1,0],[0,0]];
  uv.forEach(([u,v],i)=>{
    const p=new T.Vector3(u,v,1).applyMatrix3(h);
    assert.ok(Math.abs(p.x/p.z-corners[i][0]/1000)<1e-10);
    assert.ok(Math.abs(p.y/p.z-(1-corners[i][1]/800))<1e-10);
  });
  assert.ok(Math.abs(h.elements[2])+Math.abs(h.elements[5])>.001,'requires perspective correction, not an affine crop');
});

test('all measured reference patches sample inside their original photograph',()=>{
  for(const region of Object.values(photoRegions)){
    const h=photoHomography(region.corners,...photoSources[region.source].size);
    for(let y=0;y<=10;y++)for(let x=0;x<=10;x++){
      const p=new T.Vector3(x/10,y/10,1).applyMatrix3(h),u=p.x/p.z,v=p.y/p.z;
      assert.ok(Number.isFinite(u)&&Number.isFinite(v));assert.ok(u>=0&&u<=1&&v>=0&&v<=1);
    }
  }
});

test('collapsed source quads fail instead of producing invalid shader coordinates',()=>{
  assert.throws(()=>photoHomography([[1,1],[1,1],[1,1],[1,1]],100,100),/Degenerate/);
});

test('borrowed surfaces preserve lighting maps and toggle without rebuilding materials',()=>{
  const originalMap=new T.Texture(),bumpMap=new T.Texture(),roughnessMap=new T.Texture(),photo=new T.Texture();
  const material=new T.MeshStandardMaterial({map:originalMap,bumpMap,roughnessMap,vertexColors:true,roughness:.8});
  const controller=attachPhotoSurface(material,photo,[[0,0],[100,0],[100,100],[0,100]],[100,100]);
  const shader={uniforms:{},fragmentShader:T.ShaderLib.standard.fragmentShader};
  material.onBeforeCompile(shader);
  assert.equal(material.map,originalMap);assert.equal(material.bumpMap,bumpMap);assert.equal(material.roughnessMap,roughnessMap);
  assert.equal(material.vertexColors,true);assert.equal(material.roughness,.8);
  assert.equal(shader.uniforms.reusePhoto.value,photo);
  assert.ok(shader.fragmentShader.includes('#include <lights_fragment_begin>'));
  assert.ok(!shader.fragmentShader.includes('#include <map_fragment>'));
  const version=material.version;
  controller.setEnabled(false);assert.equal(shader.uniforms.reuseEnabled.value,0);
  controller.setEnabled(true);assert.equal(shader.uniforms.reuseEnabled.value,1);
  assert.equal(material.version,version);
});
