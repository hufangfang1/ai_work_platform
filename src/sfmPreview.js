import * as T from 'three';
import {OrbitControls} from 'three/addons/controls/OrbitControls.js';
const renderer=new T.WebGLRenderer({antialias:true});renderer.setPixelRatio(Math.min(devicePixelRatio,2));renderer.setSize(innerWidth,innerHeight);document.querySelector('#view').append(renderer.domElement);
const viewport=document.querySelector('#view');
function resize(){const {width,height}=viewport.getBoundingClientRect();camera.aspect=width/height;camera.updateProjectionMatrix();renderer.setSize(width,height)}
const scene=new T.Scene();scene.background=new T.Color('#151b20');
const camera=new T.PerspectiveCamera(55,innerWidth/innerHeight,.001,100000),controls=new OrbitControls(camera,renderer.domElement);controls.enableDamping=true;
const content=new T.Group();scene.add(content);let entries=[],active,center=new T.Vector3(),radius=1;
const selector=document.querySelector('#models'),status=document.querySelector('#status');
function showPhoto(){
 const cam=active?.model.cameras.find(c=>c.name===document.querySelector('#photo').value);if(!cam)return;
 const root=document.querySelector('#evidence');root.replaceChildren();const img=document.createElement('img');img.src='/home-photos/'+(/^0[1-5]-/.test(cam.name)?'':'references/')+cam.name;img.style.width='100%';img.alt=cam.name;root.append(img);
 const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('viewBox',`0 0 ${cam.size.join(' ')}`);svg.style.cssText='position:absolute;inset:0;width:100%;height:100%;pointer-events:none';
 for(const [x,y] of cam.observations){const c=document.createElementNS(svg.namespaceURI,'circle');c.setAttribute('cx',x);c.setAttribute('cy',y);c.setAttribute('r',cam.size[0]/150);c.setAttribute('fill','#00ffd0');c.setAttribute('stroke','#07342d');c.setAttribute('stroke-width',cam.size[0]/1000);svg.append(c)}root.append(svg);
}
document.querySelector('#photo').onchange=showPhoto;
function reset(){resize();const distance=radius/2/Math.sin(T.MathUtils.degToRad(camera.fov/2))/Math.min(1,camera.aspect)*1.1;camera.position.copy(center).add(new T.Vector3(.12,-.08,1).normalize().multiplyScalar(distance));camera.near=radius/10000;camera.far=radius*1000;camera.updateProjectionMatrix();controls.target.copy(center);controls.update()}
function draw(){
 content.traverse(o=>{o.geometry?.dispose();o.material?.dispose()});content.clear();active=entries[Number(selector.value)];if(!active)return;
 const points=active.model.cloud.filter(p=>!document.querySelector('#reliable').checked||p.track>=3),pos=[],colors=[];
 for(const pt of points){pos.push(...pt.p);const c=new T.Color().setRGB(...pt.c.map(v=>v/255),T.SRGBColorSpace);colors.push(c.r,c.g,c.b)}
 const geometry=new T.BufferGeometry();geometry.setAttribute('position',new T.Float32BufferAttribute(pos,3));geometry.setAttribute('color',new T.Float32BufferAttribute(colors,3));
 content.add(new T.Points(geometry,new T.PointsMaterial({size:Number(document.querySelector('#size').value),sizeAttenuation:false,vertexColors:document.querySelector('#photoColor').checked,color:document.querySelector('#photoColor').checked?'#ffffff':'#63ffe0'})));
 // Median-based bounds avoid a few unstable distant points hiding the model.
 const axis=[0,1,2].map(i=>active.model.cloud.map(p=>p.p[i]).sort((a,b)=>a-b));const lo=new T.Vector3(...axis.map(a=>a[Math.floor(a.length*.02)])),hi=new T.Vector3(...axis.map(a=>a[Math.floor(a.length*.98)]));center.copy(lo).add(hi).multiplyScalar(.5);radius=Math.max(hi.distanceTo(lo),.01);
 const lines=[];
 for(const cam of active.model.cameras){const c=new T.Vector3(...cam.center),r=cam.rotation,s=radius*.025;const corners=[[-1,-.75,1.3],[1,-.75,1.3],[1,.75,1.3],[-1,.75,1.3]].map(v=>new T.Vector3(...r.map(row=>row.reduce((a,n,i)=>a+n*v[i]*s,0))).add(c));for(let i=0;i<4;i++)lines.push(...c.toArray(),...corners[i].toArray(),...corners[i].toArray(),...corners[(i+1)%4].toArray());}
 content.add(new T.LineSegments(new T.BufferGeometry().setAttribute('position',new T.Float32BufferAttribute(lines,3)),new T.LineBasicMaterial({color:'#eaa960'})));
 const photoSelect=document.querySelector('#photo');photoSelect.replaceChildren();
 for(const cam of active.model.cameras){const option=document.createElement('option');option.value=cam.name;option.textContent=cam.name;photoSelect.append(option)}
 showPhoto();
 const m=active.model;status.textContent=`已定位 ${m.images.length} / 10 张照片\n${m.points} 个三维点 · 当前显示 ${points.length}\n平均重投影误差 ${m.mean_reprojection_error.toFixed(2)} 像素\n至少 3 图支持：${m.tracks_3plus} 点\n\n${m.images.join('\n')}`;
}
try{const response=await fetch('/sfm-results/reconstruction.json');if(!response.ok)throw Error('重建结果尚未导出');const data=await response.json();for(const run of data)for(const model of run.models)entries.push({run:run.run,model});if(!entries.length){status.textContent='本次试验没有恢复出可展示的三维模型。';}else{entries.forEach((e,i)=>{const o=document.createElement('option');o.value=i;o.textContent=`${({'static-mask':'排除遮挡','opencv-baseline':'原照基线','focal-0.65':'焦距初值 0.65','focal-0.85':'焦距初值 0.85','focal-1.2':'焦距初值 1.2'}[e.run]||e.run)} · 模型 ${e.model.id+1}`;selector.append(o)});draw();reset()}}catch(e){document.querySelector('#error').textContent=e.message}
selector.onchange=()=>{draw();reset()};document.querySelector('#reliable').onchange=draw;document.querySelector('#size').oninput=draw;document.querySelector('#photoColor').onchange=draw;document.querySelector('#reset').onclick=reset;
window.addEventListener('resize',resize);resize();
renderer.setAnimationLoop(()=>{controls.update();renderer.render(scene,camera)});
