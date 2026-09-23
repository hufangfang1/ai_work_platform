"""Export predicted surfaces plus cross-view depth consistency, not Gaussian weights."""
import json,sys
from pathlib import Path
import numpy as np
from scipy.ndimage import maximum_filter, minimum_filter, binary_closing
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/sfm'))
from masks import EXCLUDE
out=ROOT/'public/neural-results';out.mkdir(exist_ok=True)
all_stats=[]
for group in ['main','west','yard','all']:
 base=ROOT/'artifacts/neural'/group
 if not (base/'prediction.npz').exists():continue
 a=np.load(base/'prediction.npz');meta=json.loads((base/'summary.json').read_text());points=a['points'];depth=a['depth'][...,0];conf=a['confidence'];colors=a['colors'];extr=a['extrinsics'];intr=a['intrinsics'];n,h,w=depth.shape
 yy,xx=np.mgrid[:h,:w];valid=np.isfinite(points).all(-1)&(depth>0)
 allowed=valid.copy()
 for i,name in enumerate(meta['photos']):
  allowed[i]&=depth[i]<np.quantile(depth[i],.985)
  valid[i]&=(conf[i]>np.quantile(conf[i],.30))&(depth[i]<np.quantile(depth[i],.985))
  # Discard photographed people / moving vehicles only in the display, not in inference.
  for x0,y0,x1,y1 in EXCLUDE.get(Path(name).name,[]):allowed[i,int(y0*h):int(y1*h),int(x0*w):int(x1*w)]=False
  allowed[i,int(h*.88):,int(w*.76):]=False
  valid[i]&=allowed[i]
 support=np.ones(depth.shape,dtype=np.uint8)
 residuals=[]
 for i in range(n):
  world=points[i].reshape(-1,3)
  for j in range(n):
   if i==j:continue
   cam=world@extr[j,:3,:3].T+extr[j,:3,3];q=cam@intr[j].T;z=cam[:,2];uv=q[:,:2]/np.maximum(q[:,2:],1e-8)
   good=np.isfinite(uv).all(1)&(z>0)&(uv[:,0]>=0)&(uv[:,0]<w-1)&(uv[:,1]>=0)&(uv[:,1]<h-1)
   ids=np.flatnonzero(good);u=np.clip(np.rint(uv[ids,0]).astype(int),0,w-1);v=np.clip(np.rint(uv[ids,1]).astype(int),0,h-1)
   error=np.abs(z[ids]-depth[j,v,u])/np.maximum(depth[j,v,u],1e-8)
   consistent=valid[j,v,u]&(error<.05);support[i].reshape(-1)[ids[consistent]]+=1
   residuals.extend(error[valid[j,v,u]].tolist())
 # Restore observed, locally smooth low-confidence surface samples only.
 # No depth interpolation or triangulation across masked people / occlusions.
 recovered=np.zeros_like(valid)
 for i in range(n):
  spread=(maximum_filter(depth[i],size=5)-minimum_filter(depth[i],size=5))/np.maximum(depth[i],1e-8)
  small_holes=binary_closing(valid[i],iterations=2)
  candidate=(conf[i]>np.quantile(conf[i],.08)) & (spread<.035)
  recovered[i]=allowed[i]&~valid[i]&candidate&(small_holes|(support[i]>=2)|(conf[i]>np.quantile(conf[i],.15)))
 exported=[];cams=[]
 for i,name in enumerate(meta['photos']):
  pts=points[i,::2,::2].copy();pts[...,1:]*=-1;rgb=colors[i,::2,::2];original=valid[i,::2,::2];restored=recovered[i,::2,::2];mask=original|restored;sup=support[i,::2,::2];dep=depth[i,::2,::2];sh,sw=mask.shape
  # Skip discontinuities rather than triangulating across people, sky or occlusions.
  ids=np.arange(sh*sw,dtype=np.uint32).reshape(sh,sw)
  faces=[]
  for tri in [(ids[:-1,:-1],ids[1:,:-1],ids[:-1,1:]),(ids[:-1,1:],ids[1:,:-1],ids[1:,1:])]:
   t=np.stack(tri,-1).reshape(-1,3);d=dep.ravel()[t]
   keep=mask.ravel()[t].all(1)&((d.max(1)-d.min(1))/np.maximum(d.mean(1),1e-8)<.06)
   faces.append(t[keep])
  faces=np.concatenate(faces);filename=f'{group}-{i}.json'
  payload={'positions':pts.reshape(-1,3).round(6).tolist(),'colors':rgb.reshape(-1,3).tolist(),'valid':original.ravel().astype(int).tolist(),'recovered':restored.ravel().astype(int).tolist(),'support':sup.ravel().tolist(),'indices':faces.ravel().tolist()}
  (out/filename).write_text(json.dumps(payload,separators=(',',':')))
  f=np.diag([1.,-1.,-1.]);R=extr[i,:3,:3];t=extr[i,:3,3];center=f@(-R.T@t);rotation=f@R.T@f
  cams.append({'name':name,'position':center.tolist(),'rotation':rotation.tolist(),'fov':float(np.degrees(2*np.arctan(h/(2*intr[i,1,1])))),'targetDistance':float(np.median(depth[i][valid[i]]))})
  exported.append({'file':filename,'name':name,'points':int(original.sum()),'recovered_points':int(restored.sum()),'faces':len(faces),'consistent_points':int((mask&(sup>=2)).sum())})
 meta.update({'surfaces':exported,'cameras':cams,'valid_pixels':int(valid.sum()),'consistent_pixels':int((valid&(support>=2)).sum()),'median_cross_view_relative_depth_error':float(np.median(residuals)) if residuals else None})
 all_stats.append(meta);(base/'validation.json').write_text(json.dumps(meta,ensure_ascii=False,indent=2))
(out/'manifest.json').write_text(json.dumps(all_stats,ensure_ascii=False,indent=2));print([(x['group'],x['valid_pixels'],x['consistent_pixels']) for x in all_stats])
