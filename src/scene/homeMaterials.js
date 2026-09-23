import * as T from 'three'

export function makeHomeMaterials(renderer) {
  const textures = new Set(), materials = new Set()
  let seed=1949
  const random=()=>{seed=(seed*1664525+1013904223)>>>0;return seed/4294967296}
  function canvasMap(draw, size=1024, colorSpace=T.SRGBColorSpace) {
    const canvas=document.createElement('canvas');canvas.width=canvas.height=size
    const c=canvas.getContext('2d');draw(c,size)
    const t=new T.CanvasTexture(canvas);t.colorSpace=colorSpace
    t.wrapS=t.wrapT=T.RepeatWrapping;t.anisotropy=Math.min(8,renderer.capabilities.getMaxAnisotropy());textures.add(t);return t
  }
  function grain(c,s,count=50000) {
    for(let i=0;i<count;i++) {const k=random();c.fillStyle=k>.5?`rgba(250,241,220,${random()*.12})`:`rgba(27,27,20,${random()*.16})`;c.fillRect(random()*s,random()*s,1+random()*3,1+random()*2)}
  }
  // Periodic value fields give weathering several scales without conspicuous
  // polygon-shaped stains or texture seams. They are reused by the PBR maps.
  function noiseField(cellsX,cellsY=cellsX) {
    const values=Float32Array.from({length:cellsX*cellsY},()=>random()*2-1)
    return (u,v)=>{
      const x=u*cellsX,y=v*cellsY,ix=Math.floor(x),iy=Math.floor(y)
      let fx=x-ix,fy=y-iy;fx=fx*fx*(3-2*fx);fy=fy*fy*(3-2*fy)
      const at=(a,b)=>values[((b%cellsY+cellsY)%cellsY)*cellsX+(a%cellsX+cellsX)%cellsX]
      return (at(ix,iy)*(1-fx)+at(ix+1,iy)*fx)*(1-fy)+(at(ix,iy+1)*(1-fx)+at(ix+1,iy+1)*fx)*fy
    }
  }
  function fieldMap(sample,size=1024,data=false) {
    return canvasMap((c,s)=>{
      const pixels=c.createImageData(s,s),a=pixels.data
      for(let y=0;y<s;y++)for(let x=0;x<s;x++){
        const value=sample(x/s,y/s),i=(y*s+x)*4
        if(typeof value==='number')a[i]=a[i+1]=a[i+2]=value
        else {a[i]=value[0];a[i+1]=value[1];a[i+2]=value[2]}
        a[i+3]=255
      }
      c.putImageData(pixels,0,0)
    },size,data?T.NoColorSpace:T.SRGBColorSpace)
  }
  const relief=new Map(),roughness=new Map()
  function addSurfaceWear(color,height,{pits=1200,cracks=20,contrast=.28}={}) {
    const marks=Array.from({length:pits},()=>({x:random(),y:random(),r:.0006+random()*.0022,alpha:.15+random()*contrast}))
    const lines=Array.from({length:cracks},()=>{
      let x=.06+random()*.88,y=.05+random()*.85;const points=[[x,y]]
      for(let step=0;step<4+Math.floor(random()*5);step++){x+=random()*.018-.009;y+=.003+random()*.011;points.push([x,y])}
      return points
    })
    for(const [map,data] of [[color,false],[height,true]]){
      const c=map.image.getContext('2d'),s=map.image.width
      for(const mark of marks){
        c.fillStyle=data?`rgba(28,28,28,${mark.alpha})`:`rgba(70,72,65,${mark.alpha})`
        c.beginPath();c.moveTo((mark.x-mark.r)*s,mark.y*s);c.lineTo(mark.x*s,(mark.y-mark.r*.65)*s);c.lineTo((mark.x+mark.r)*s,(mark.y+mark.r*.4)*s);c.lineTo((mark.x-mark.r*.5)*s,(mark.y+mark.r)*s);c.closePath();c.fill()
      }
      c.strokeStyle=data?'rgba(25,25,25,.4)':'rgba(68,68,61,.32)';c.lineWidth=Math.max(.45,s*.0007)
      for(const points of lines){c.beginPath();points.forEach(([x,y],i)=>c[i?'lineTo':'moveTo'](x*s,y*s));c.stroke()}
      map.needsUpdate=true
    }
  }
  const plasterCloud=noiseField(5),plasterWear=noiseField(23),plasterGrain=noiseField(260)
  const plaster=fieldMap((u,v)=>{
    const cloud=plasterCloud(u,v),wear=plasterWear(u,v),fine=plasterGrain(u,v)
    const shade=cloud*12+wear*8+fine*7
    return [195+shade,193+shade,183+shade]
  })
  relief.set(plaster,fieldMap((u,v)=>128+plasterGrain(u,v)*24+plasterWear(u,v)*4,512,true))
  roughness.set(plaster,fieldMap((u,v)=>239+plasterWear(u,v)*10,512,true))
  addSurfaceWear(plaster,relief.get(plaster),{pits:2400,cracks:34,contrast:.32})

  // The same irregular brick polygons drive all three layers. A pale brick is
  // still raised: mortar depth must never be inferred from the brick's color.
  const brickShapes=[]
  const brickPalette=[[148,126,111],[144,131,117],[137,122,108],[148,133,115],[128,123,111],[154,133,117]]
  for(let row=0;row<8;row++)for(let col=0;col<4;col++){
    const x=col*.25+(row%2?.125:0),y=row*.125
    const top=.005+random()*.003,bottom=.116-random()*.004
    brickShapes.push({x,y,color:brickPalette[Math.floor(random()*brickPalette.length)],tone:random()*8-4,
      points:[[.009,top+.003],[.075,top],[.169,top+.002],[.240,top+.004],[.244,.047],[.240,bottom-.003],[.164,bottom],[.072,bottom-.002],[.007,bottom-.006],[.005,.049]]})
  }
  const brickCloud=noiseField(17),brickGrain=noiseField(340)
  function brickMap(kind) {
    const size=kind==='color'?1024:512
    return canvasMap((c,s)=>{
      c.fillStyle=kind==='height'?'#515151':kind==='roughness'?'#f5f5f5':'#969285';c.fillRect(0,0,s,s)
      for(const b of brickShapes)for(const shift of [-1,0]){
        c.beginPath();b.points.forEach(([px,py],i)=>c[i?'lineTo':'moveTo']((b.x+px+shift)*s,(b.y+py)*s));c.closePath()
        if(kind==='color')c.fillStyle=`rgb(${b.color.map(v=>v+b.tone).join(',')})`
        else c.fillStyle=kind==='height'?'#adadad':'#e5e5e5'
        c.fill()
        // The narrow bevel exists only in height; color has no dark cartoon outline.
        if(kind==='height'){c.strokeStyle='#818181';c.lineWidth=s*.005;c.lineJoin='round';c.stroke()}
      }
      const pixels=c.getImageData(0,0,s,s),a=pixels.data
      for(let y=0;y<s;y++)for(let x=0;x<s;x++){
        const i=(y*s+x)*4,cloud=brickCloud(x/s,y/s),fine=brickGrain(x/s,y/s)
        const change=kind==='color'?cloud*10+fine*7:kind==='height'?fine*12:cloud*6+fine*4
        a[i]+=change;a[i+1]+=change;a[i+2]+=change
      }
      c.putImageData(pixels,0,0)
      // Small lime deposits cross both brick and mortar without changing depth.
      if(kind==='color')for(let i=0;i<100;i++){
        const x=random()*s,y=random()*s,r=3+random()*15,g=c.createRadialGradient(x,y,0,x,y,r)
        g.addColorStop(0,'rgba(204,197,174,.17)');g.addColorStop(1,'rgba(204,197,174,0)');c.fillStyle=g;c.fillRect(x-r,y-r,r*2,r*2)
      }
    },size,kind==='color'?T.SRGBColorSpace:T.NoColorSpace)
  }
  const brick=brickMap('color');relief.set(brick,brickMap('height'));roughness.set(brick,brickMap('roughness'))
  const groundCloud=noiseField(4),groundPatches=noiseField(17),groundGrain=noiseField(300)
  const ground=fieldMap((u,v)=>{
    const shade=groundCloud(u,v)*14+groundPatches(u,v)*10+groundGrain(u,v)*9
    return [151+shade,147+shade,132+shade]
  })
  relief.set(ground,fieldMap((u,v)=>128+groundGrain(u,v)*29+groundPatches(u,v)*5,512,true))
  roughness.set(ground,fieldMap((u,v)=>239+groundCloud(u,v)*10+groundGrain(u,v)*5,512,true))
  addSurfaceWear(ground,relief.get(ground),{pits:1800,cracks:16,contrast:.2})
  const soilCloud=noiseField(5),soilPatches=noiseField(31),soilGrain=noiseField(370)
  const soil=fieldMap((u,v)=>{
    const broad=soilCloud(u,v),patch=soilPatches(u,v),fine=soilGrain(u,v)
    const shade=broad*14+patch*16+fine*12
    return [133+shade,119+shade*.91,100+shade*.75]
  })
  relief.set(soil,fieldMap((u,v)=>128+soilGrain(u,v)*32+soilPatches(u,v)*9,512,true))
  roughness.set(soil,fieldMap((u,v)=>245+soilCloud(u,v)*6+soilGrain(u,v)*4,512,true))
  const metalCloud=noiseField(6),metalStreak=noiseField(90,4),metalPits=noiseField(230)
  const roofMetal=fieldMap((u,v)=>{
    const cloud=metalCloud(u,v),streak=metalStreak(u,v),rust=Math.max(0,cloud-.2)*19,shade=cloud*8+streak*7+metalPits(u,v)*2
    return [88+shade+rust,88+shade+rust*.35,78+shade]
  })
  relief.set(roofMetal,fieldMap((u,v)=>128+metalPits(u,v)*13+metalStreak(u,v)*4,512,true))
  roughness.set(roofMetal,fieldMap((u,v)=>215+metalCloud(u,v)*18+metalStreak(u,v)*8,512,true))
  // Individual geometric bricks carry their own fired-clay tint. This is only
  // neutral mineral grain: repeating a whole brick wall on them doubles joints.
  const clayPores=noiseField(230),clayWear=noiseField(27);
  const firedClay=fieldMap((u,v)=>{
    const shade=234+clayPores(u,v)*14+clayWear(u,v)*8;
    return [shade,shade,shade];
  });
  relief.set(firedClay,fieldMap((u,v)=>128+clayPores(u,v)*37+clayWear(u,v)*7,512,true));
  roughness.set(firedClay,fieldMap((u,v)=>238+clayWear(u,v)*11,512,true));
  const paintWear=noiseField(15),paintPores=noiseField(240),paintStreak=noiseField(75,5);
  const gatePaint=fieldMap((u,v)=>{
    const shade=paintWear(u,v)*7+paintPores(u,v)*3+paintStreak(u,v)*5;
    return [99+shade,60+shade,53+shade];
  });
  relief.set(gatePaint,fieldMap((u,v)=>128+paintPores(u,v)*15,512,true));
  roughness.set(gatePaint,fieldMap((u,v)=>214+paintWear(u,v)*17+paintStreak(u,v)*8,512,true));
  const bark=canvasMap((c,s)=>{c.fillStyle='#716c5b';c.fillRect(0,0,s,s);for(let i=0;i<700;i++){c.strokeStyle=i%2?'#8b8572':'#504e40';c.lineWidth=1+random()*5;let x=random()*s,y=random()*s;c.beginPath();c.moveTo(x,y);c.bezierCurveTo(x+8,y+50,x-8,y+90,x+3,y+170);c.stroke()}grain(c,s)})
  const tarp=canvasMap((c,s)=>{c.fillStyle='#34716d';c.fillRect(0,0,s,s);for(let i=0;i<50;i++){let x=random()*s;c.beginPath();c.moveTo(x,0);c.bezierCurveTo(x-45,s*.3,x+40,s*.7,x,s);c.strokeStyle=i%2?'rgba(10,43,38,.2)':'rgba(160,201,172,.15)';c.lineWidth=1+random()*8;c.stroke()}grain(c,s,15000)})
  const wood=canvasMap((c,s)=>{c.fillStyle='#574335';c.fillRect(0,0,s,s);for(let i=0;i<500;i++){c.fillStyle=i%2?'rgba(16,12,8,.15)':'rgba(158,119,75,.14)';c.fillRect(random()*s,random()*s,random()*4,40+random()*500)}grain(c,s,20000)})
  const woodFibres=noiseField(240,6),woodVarnish=noiseField(11,18);
  relief.set(wood,fieldMap((u,v)=>128+woodFibres(u,v)*17+woodVarnish(u,v)*3,512,true));
  roughness.set(wood,fieldMap((u,v)=>216+woodVarnish(u,v)*20,512,true));
  const mat=(color,extra={})=>{const m=new T.MeshStandardMaterial({color,roughness:.95,...extra});materials.add(m);return m}
  // Data maps are linear, independently owned, and share the color map's UV scale.
  for(const source of [bark,tarp]){
    const t=canvasMap((c,s)=>{c.drawImage(source.image,0,0,s,s);const data=c.getImageData(0,0,s,s);for(let i=0;i<data.data.length;i+=4){const a=data.data,v=(a[i]+a[i+1]+a[i+2])/3;a[i]=a[i+1]=a[i+2]=v}c.putImageData(data,0,0)},512,T.NoColorSpace)
    relief.set(source,t)
  }
  function mapped(map,repeat=[1,1],extra={}) {
    const copy=map.clone();copy.repeat.set(...repeat);textures.add(copy)
    const bump=relief.get(map)?.clone();if(bump){bump.repeat.set(...repeat);textures.add(bump)}
    const rough=roughness.get(map)?.clone();if(rough){rough.repeat.set(...repeat);textures.add(rough)}
    const m=mat('#ffffff',{map:copy,bumpMap:bump??null,roughnessMap:rough??null,roughness:rough?1:.95,bumpScale:map===brick?.022:map===ground?.017:map===soil?.012:map===roofMetal?.005:map===plaster?.015:map===firedClay?.005:map===gatePaint?.0015:map===wood?.003:.009,...extra})
    m.userData.source=map;m.userData.unit=map===brick?[1.04,.64]:map===wood?[1,3]:map===roofMetal?[2,3]:map===firedClay?[1,1]:map===gatePaint?[1.2,1.2]:[4,4];return m
  }
  // Replace only color on already-created materials. The independent linear
  // microrelief/roughness maps remain intact; a bright stain is not a bump.
  // Ownership transfers here so both the loaded source and clones are freed.
  function useAlbedo(source,texture){
    texture.colorSpace=T.SRGBColorSpace;
    texture.wrapS=texture.wrapT=T.MirroredRepeatWrapping;
    texture.anisotropy=Math.min(8,renderer.capabilities.getMaxAnisotropy());
    textures.add(texture);
    materials.forEach(m=>{
      if(m.userData.source!==source)return;
      const previous=m.map,next=texture.clone();next.repeat.copy(previous.repeat);
      next.offset.copy(previous.offset);textures.add(next);m.map=next;m.needsUpdate=true;
      previous.dispose();textures.delete(previous);
    });
  }
  // Sample bounded patches of the original photos in the shader, keeping the
  // source files untouched. Repeat is within the patch, not the whole photo.
  function photoSurface(source,photo,rect){
    const [x,y,w,h]=rect,iw=photo.image.width,ih=photo.image.height
    materials.forEach(m=>{if(m.userData.source!==source)return
      m.map.image=photo.image;m.map.needsUpdate=true
      m.onBeforeCompile=shader=>{shader.fragmentShader=shader.fragmentShader.replace('#include <map_fragment>',`\n#ifdef USE_MAP\nvec2 homeUv=fract(vMapUv)*vec2(${w/iw},${h/ih})+vec2(${x/iw},${1-(y+h)/ih});\nvec4 sampledDiffuseColor=texture2D(map,homeUv);\ndiffuseColor*=sampledDiffuseColor;\n#endif\n`)}
      m.customProgramCacheKey=()=>`home-photo-${rect.join('-')}`;m.needsUpdate=true
    })
  }
  function writing(text,bg='#871f20',fg='#d3ab53',vertical=false){
    const map=canvasMap((c,s)=>{c.fillStyle=bg;c.fillRect(0,0,s,s);c.fillStyle=fg;c.textAlign='center';c.textBaseline='middle';c.font=`${vertical?Math.min(125,s/Math.max(1,[...text].length)*.85):s*.76}px serif`;if(vertical){[...text].forEach((ch,i)=>c.fillText(ch,s/2,(i+.5)*s/[...text].length))}else c.fillText(text,s/2,s/2,s*.86);grain(c,s,8000)},512)
    return mat('#ffffff',{map,side:T.DoubleSide})
  }
  return {mat,mapped,writing,photoSurface,useAlbedo,maps:{plaster,brick,ground,soil,bark,tarp,wood,roofMetal,firedClay,gatePaint},dispose(){textures.forEach(t=>t.dispose());materials.forEach(m=>m.dispose())}}
}
