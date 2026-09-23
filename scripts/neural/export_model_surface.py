"""Transfer observed neural mesh triangles/UVs to the model facade plane.
Retain holes and exclude joinery/people; no generated texture or missing faces.
"""
import json
from pathlib import Path
import numpy as np
from scipy.ndimage import distance_transform_edt
R=Path(__file__).resolve().parents[2]
n=json.loads((R/'src/scene/neuralOpeningFit.json').read_text())
f=json.loads((R/'src/scene/photoCameraFit.json').read_text())
d=json.loads((R/'public/neural-results/main-0.json').read_text())
meta=json.loads((R/'artifacts/neural/main/summary.json').read_text())
A=[];b=[];offset=n.get('windowOffsetX',0)
for m in f['landmarks']:
 u,v=np.array(m['photo'])/[1440,1080];x,y=m['local'][:2]
 if m['name'].startswith('window '):x-=offset
 A.extend([[u,v,1,0,0,0,-x*u,-x*v],[0,0,0,u,v,1,-y*u,-y*v]]);b.extend([x,y])
H=np.r_[np.linalg.lstsq(A,b,rcond=None)[0],1].reshape(3,3)
k=np.arange(len(d['positions']));cols=(meta['width']+1)//2
uv=np.c_[((k%cols)*2+.5)/meta['width'],((k//cols)*2+.5)/meta['height']]
q=np.c_[uv,np.ones(len(k))]@H.T;xy=q[:,:2]/q[:,2:]
left=n['windowX']-offset+n['windowWidth']/2;right=n['doorCenterX']-n['openingWidth']/2
xy[:,0]+=offset*np.clip((right-xy[:,0])/(right-left),0,1)
x,y=xy.T
keep=(x>-4.55)&(x<3.8)&(y>.78)&(y<3.65)
tri=np.array(d['indices']).reshape(-1,3);tri=tri[keep[tri].all(1)]
# Conservatively discard every triangle touching a model opening or garment.
rects=[(n['windowX']-n['windowWidth']/2-.05,n['windowX']+n['windowWidth']/2+.05,n['windowSill']-.07,n['windowTop']+.06),(n['doorCenterX']-n['openingWidth']/2-.06,n['doorCenterX']+n['openingWidth']/2+.06,0,3.85),(-2.55+offset,-1.83+offset,1.40,3.2)]
for l,r,b,t in rects:
 p=xy[tri];intersects=(p[:,:,0].max(1)>l)&(p[:,:,0].min(1)<r)&(p[:,:,1].max(1)>b)&(p[:,:,1].min(1)<t);tri=tri[~intersects]
used,indices=np.unique(tri,return_inverse=True)
coverage=np.zeros(len(k),bool);coverage[used]=True
edge=distance_transform_edt(coverage.reshape(-1,cols)).ravel()[used]
alpha=(.90*np.clip((edge-1)/4,0,1)).round(4).tolist()
payload={'alpha':alpha,'source':'neural-results/main-0.json','method':'observed neural triangles constrained to calibrated model wall; openings excluded','positions':np.c_[xy[used],np.full(len(used),.198)].round(6).ravel().tolist(),'uv':np.c_[uv[used,0],1-uv[used,1]].round(7).ravel().tolist(),'indices':indices.tolist()}
assert len(indices)>0 and np.isfinite(payload['positions']).all()
out=R/'public/neural-results/model-facade.json';out.write_text(json.dumps(payload,separators=(',',':')))
print({'vertices':len(used),'triangles':len(indices)//3,'bytes':out.stat().st_size})
