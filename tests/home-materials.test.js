import test from 'node:test'
import assert from 'node:assert/strict'
import * as T from 'three'
import {makeHomeMaterials} from '../src/scene/homeMaterials.js'

// Material ownership and data-map contracts need no GPU or browser. This small
// context double accepts drawing commands; visual appearance is checked in-app.
function canvasDouble() {
  const context={
    fillRect(){},beginPath(){},moveTo(){},lineTo(){},closePath(){},fill(){},stroke(){},bezierCurveTo(){},drawImage(){},fillText(){},
    createRadialGradient(){return {addColorStop(){}}},
    createImageData(w,h){return {data:new Uint8ClampedArray(w*h*4)}},
    getImageData(x,y,w,h){return {data:new Uint8ClampedArray(w*h*4)}},putImageData(){},
  }
  return {width:0,height:0,getContext(){return context}}
}

test('weathered PBR maps keep data linear, repeat aligned and resources disposable',()=>{
  const prior=globalThis.document
  globalThis.document={createElement(name){assert.equal(name,'canvas');return canvasDouble()}}
  let materials
  try {
    materials=makeHomeMaterials({capabilities:{getMaxAnisotropy:()=>4}})
    const used=[]
    for(const name of ['plaster','brick','ground','roofMetal','firedClay','gatePaint','wood','soil']){
      const source=materials.maps[name],material=materials.mapped(source,[2,3])
      used.push(material)
      assert.equal(material.map.colorSpace,T.SRGBColorSpace)
      assert.equal(material.map.image.width,name==='brick'?2048:1024)
      assert.equal(material.map.source,source.source)
      for(const texture of [material.bumpMap,material.roughnessMap]){
        assert.equal(texture.colorSpace,T.NoColorSpace)
        assert.equal(texture.image.width,1024)
        assert.deepEqual(texture.repeat.toArray(),[2,3])
        assert.equal(texture.wrapS,T.RepeatWrapping)
        assert.equal(texture.anisotropy,4)
      }
      assert.notEqual(material.bumpMap.image,material.map.image)
      assert.notEqual(material.roughnessMap.image,material.map.image)
      assert.ok(material.bumpScale>0&&material.bumpScale<.03)
    }
    assert.deepEqual(used[3].userData.unit,[2,3])
    assert.deepEqual(used[4].userData.unit,[1,1]);
    assert.deepEqual(used[5].userData.unit,[1.2,1.2]);
    assert.ok(used[5].bumpScale<=.002);
    assert.deepEqual(used[6].userData.unit,[1,3]);
    assert.ok(used[6].bumpScale<=.003);
    assert.deepEqual(used[7].userData.unit,[4,4]);
    assert.ok(used[7].bumpScale>0&&used[7].bumpScale<=.012);
    const original=used[0].map,bump=used[0].bumpMap,rough=used[0].roughnessMap;
    let replacedDisposed=false;
    original.addEventListener('dispose',()=>{replacedDisposed=true});
    const generated=new T.Texture({width:2048,height:2048});
    materials.useAlbedo(materials.maps.plaster,generated);
    assert.ok(replacedDisposed);
    assert.equal(used[0].map.image,generated.image);
    assert.equal(used[0].map.colorSpace,T.SRGBColorSpace);
    assert.equal(used[0].map.wrapS,T.MirroredRepeatWrapping);
    assert.deepEqual(used[0].map.repeat.toArray(),[2,3]);
    assert.equal(used[0].bumpMap,bump);
    assert.equal(used[0].roughnessMap,rough);
    assert.notEqual(used[1].map.image,generated.image);
    const watched=used.flatMap(m=>[m,m.map,m.bumpMap,m.roughnessMap]),disposed=new Set()
    watched.push(generated);
    watched.forEach(resource=>resource.addEventListener('dispose',()=>disposed.add(resource)))
    materials.dispose();materials=null
    assert.equal(disposed.size,watched.length)
  } finally {
    materials?.dispose()
    if(prior===undefined)delete globalThis.document
    else globalThis.document=prior
  }
})
