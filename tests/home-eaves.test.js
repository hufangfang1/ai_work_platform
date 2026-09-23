import test from 'node:test'
import assert from 'node:assert/strict'
import * as T from 'three'
import {createGableRoofDeck,createGableWallClosure,createEaveShade} from '../src/scene/homeEaves.js'
import {houseLocalMinX,houseLocalMaxX} from '../src/scene/homeLayout.js'

function verifyClosed(geo){
  const p=geo.attributes.position,edges=new Map(),v=i=>[p.getX(i),p.getY(i),p.getZ(i)].map(x=>x.toFixed(5)).join(',');
  let volume=0;
  for(let i=0;i<(geo.index?.count??p.count);i+=3){
    const ids=[0,1,2].map(j=>geo.index?geo.index.getX(i+j):i+j),a=new T.Vector3().fromBufferAttribute(p,ids[0]),b=new T.Vector3().fromBufferAttribute(p,ids[1]),c=new T.Vector3().fromBufferAttribute(p,ids[2]);
    volume+=a.dot(b.clone().cross(c))/6;
    assert.ok(b.clone().sub(a).cross(c.clone().sub(a)).length()>1e-8);
    for(let j=0;j<3;j++){const key=[v(ids[j]),v(ids[(j+1)%3])].sort().join('|');edges.set(key,(edges.get(key)||0)+1)}
  }
  assert.ok(volume>0);for(const count of edges.values())assert.equal(count,2);
}

test('gable closure seals both slopes without changing the house footprint',()=>{
  const geo=createGableWallClosure({minX:houseLocalMinX,maxX:houseLocalMaxX}),p=geo.attributes.position,n=geo.attributes.normal;
  verifyClosed(geo);
  for(let i=0;i<p.count;i++){
    const x=p.getX(i),y=p.getY(i),z=p.getZ(i);
    assert.ok(x>=houseLocalMinX-1e-6&&x<=houseLocalMaxX+1e-6);
    assert.ok(y>=4-1e-6&&y<=5.200001);assert.ok(z>=-6.100001&&z<=.150001);
    const roofUnder=4.1+1.2*(1-Math.abs(z+2.85)/3.4)-.1;
    assert.ok(y<=roofUnder+1e-6);
    assert.ok(Math.abs(Math.hypot(n.getX(i),n.getY(i),n.getZ(i))-1)<1e-6);
  }
  geo.dispose();
});

test('inverted-V deck has two matching slopes, real thickness and an unchanged outline',()=>{
  const g=createGableRoofDeck(20,6.8);verifyClosed(g);const p=g.attributes.position;
  for(let i=0;i<p.count;i++){
    const y=p.getY(i),z=p.getZ(i),top=1.2*(1-Math.abs(z)/3.4);
    assert.ok(Math.abs(p.getX(i))<=10.000001&&Math.abs(z)<=3.400001);
    assert.ok(y<=top+1e-6&&y>=top-.100001);
  }
  assert.throws(()=>createGableRoofDeck(20,0),RangeError);
  assert.throws(()=>createGableWallClosure({minX:4,maxX:2}),RangeError);g.dispose();
});

test('eave contact shade is transparent at its lower edge and stays subtle',()=>{
  const g=createEaveShade(16),p=g.attributes.position,c=g.attributes.color;
  for(let i=0;i<p.count;i++){
    assert.ok(c.getW(i)>=0&&c.getW(i)<.15);
    if(p.getY(i)<-.094)assert.ok(c.getW(i)<1e-12);
  }
  assert.throws(()=>createEaveShade(-1),RangeError);g.dispose();
});
