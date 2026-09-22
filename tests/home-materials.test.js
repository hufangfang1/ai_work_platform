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
    for(const name of ['plaster','brick','ground','roofMetal']){
      const source=materials.maps[name],material=materials.mapped(source,[2,3])
      used.push(material)
      assert.equal(material.map.colorSpace,T.SRGBColorSpace)
      assert.equal(material.map.image.width,1024)
      assert.equal(material.map.source,source.source)
      for(const texture of [material.bumpMap,material.roughnessMap]){
        assert.equal(texture.colorSpace,T.NoColorSpace)
        assert.equal(texture.image.width,512)
        assert.deepEqual(texture.repeat.toArray(),[2,3])
        assert.equal(texture.wrapS,T.RepeatWrapping)
        assert.equal(texture.anisotropy,4)
      }
      assert.notEqual(material.bumpMap.image,material.map.image)
      assert.notEqual(material.roughnessMap.image,material.map.image)
      assert.ok(material.bumpScale>0&&material.bumpScale<.03)
    }
    assert.deepEqual(used[3].userData.unit,[2,3])
    const watched=used.flatMap(m=>[m,m.map,m.bumpMap,m.roughnessMap]),disposed=new Set()
    watched.forEach(resource=>resource.addEventListener('dispose',()=>disposed.add(resource)))
    materials.dispose();materials=null
    assert.equal(disposed.size,watched.length)
  } finally {
    materials?.dispose()
    if(prior===undefined)delete globalThis.document
    else globalThis.document=prior
  }
})
