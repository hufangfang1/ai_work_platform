"""Diagnostic camera fit to manually picked facade landmarks; not a survey."""
import json
from pathlib import Path
import numpy as np
def rotation(v):
 angle=np.linalg.norm(v)
 if angle<1e-12:return np.eye(3)
 x,y,z=v/angle
 k=np.array([[0,-z,y],[z,0,-x],[-y,x,0]])
 return np.eye(3)+np.sin(angle)*k+(1-np.cos(angle))*(k@k)
root=Path(__file__).resolve().parents[1]
# Local facade X is scaled by the existing house scale (.8). Image pixels
# refer to public/home-photos/01-house.jpg, 1440 x 1080.
landmarks=[
 ('window sill left',-5.19,1.575,403,355),('window sill right',-2.11,1.575,793,392),
 ('window rail left',-5.19,2.955,414,120),('window rail right',-2.11,2.955,817,165),
 ('door head left',.65,3.05,1108,185),('door head right',2.8,3.05,1314,218),
 ('door foot left',.65,.28,1072,578),('door foot right',2.8,.28,1255,586),
 ('sidelight sill left',.105,1.575,1038,407),('sidelight sill right',3.345,1.575,1338,448),
]
n=json.loads((root/'src/scene/neuralOpeningFit.json').read_text())
coords={}
for side,sign in [('left',-1),('right',1)]:
 for level,y in [('sill',n['windowSill']),('rail',n['windowRail'])]:coords[f'window {level} {side}']=(n['windowX']+sign*n['windowWidth']/2,y)
 for level,y in [('head',3.05),('foot',.28)]:coords[f'door {level} {side}']=(n['doorCenterX']+sign*n['doorWidth']/2,y)
 coords[f'sidelight sill {side}']=(n['doorCenterX']+sign*(n['sideWindowOffset']+.25),n['windowSill'])
landmarks=[(name,*coords[name],u,v) for name,x,y,u,v in landmarks]
xyz=np.array([[p[1]*.8,p[2],0] for p in landmarks]);pixels=np.array([p[3:] for p in landmarks])
def project(p):
 r=rotation(p[:3]);q=(xyz-p[3:6])@r.T
 return q[:,:2]/q[:,2,None]*np.exp(p[6])+[720,540]
def residual(p):return (project(p)-pixels).ravel()
best=None
for f in [650,950,1400,2200]:
 p=np.array([np.pi,0,0,-1,1.7,6,np.log(f)]); damping=.1
 for iteration in range(1200):
  r=residual(p); j=np.column_stack([(residual(p+np.eye(7)[i]*1e-5)-r)/1e-5 for i in range(7)])
  step=np.linalg.solve(j.T@j+damping*np.diag(np.maximum(1,np.diag(j.T@j))),-j.T@r)
  trial=np.clip(p+step,[-6,-6,-6,-20,-5,.5,np.log(550)],[6,6,6,20,15,30,np.log(4000)])
  if np.linalg.norm(residual(trial))<np.linalg.norm(r):
   p=trial; damping=max(1e-9,damping*.5)
   if np.linalg.norm(step)<1e-8:break
  else:damping=min(1e9,damping*4)
 if best is None or np.linalg.norm(residual(p))<np.linalg.norm(residual(best)):best=p.copy()
p=best; fitted=project(p); errors=np.linalg.norm(fitted-pixels,axis=1)
report={'source':'/home-photos/01-house.jpg','width':1440,'height':1080,'status':'diagnostic fit; manually estimated landmarks; facade window proportions revised; inferred dimensions','rmsPixels':float(np.sqrt(np.mean(errors**2))),'focalPixels':float(np.exp(p[6])),'verticalFovDegrees':float(np.degrees(2*np.arctan(540/np.exp(p[6])))),'cameraLocalScaled':p[3:6].tolist(),'worldToCameraCV':rotation(p[:3]).tolist(),'landmarks':[{'name':a[0],'local':[a[1],a[2],0],'photo':list(a[3:]),'fit':q.tolist(),'errorPixels':float(e)} for a,q,e in zip(landmarks,fitted,errors)]}
(root/'src/scene/photoCameraFit.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps({k:report[k] for k in ['rmsPixels','focalPixels','verticalFovDegrees','cameraLocalScaled']},indent=2))
