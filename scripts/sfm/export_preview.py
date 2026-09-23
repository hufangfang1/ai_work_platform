"""Export recovered points and camera poses only (no invented geometry)."""
from pathlib import Path
import json
import numpy as np
import pycolmap as pc
import cv2
ROOT=Path(__file__).resolve().parents[2];out=ROOT/'public/sfm-results';out.mkdir(exist_ok=True)
result=[]
for run in ['focal-0.65','focal-0.85','facade-1-7','facade-7-8','opencv-baseline','static-mask','focal-1.2']:
 root=ROOT/'artifacts/sfm'/run
 if not (root/'summary.json').exists():continue
 summary=json.loads((root/'summary.json').read_text());summary['pairs']=json.loads((root/'pairs.json' if (root/'pairs.json').exists() else ROOT/'artifacts/sfm/static-mask/pairs.json').read_text())
 for model in summary['models']:
  r=pc.Reconstruction();r.read_text(str(root/'sparse'/str(model['id'])))
  cache={}
  for point in r.points3D.values():
   samples=[]
   for el in point.track.elements:
    im=r.images[el.image_id]
    if im.name not in cache:cache[im.name]=cv2.imread(str(ROOT/'artifacts/sfm/static-mask/images'/im.name))
    xy=im.points2D[el.point2D_idx].xy;bitmap=cache[im.name];x=int(xy[0]);y=int(xy[1])
    if 0<=x<bitmap.shape[1] and 0<=y<bitmap.shape[0]:samples.append(bitmap[y,x,::-1])
   if samples:point.color=np.median(samples,axis=0).astype(np.uint8)
  r.write_text(str(root/'sparse'/str(model['id'])));r.export_PLY(str(root/'sparse'/str(model['id'])/'points.ply'))
  model['cloud']=[{'p':p.xyz.tolist(),'c':p.color.tolist(),'track':p.track.length(),'error':p.error} for p in r.points3D.values()]
  model['cameras']=[]
  for im in r.images.values():
   if not im.has_pose:continue
   pose=im.cam_from_world();model['cameras'].append({'name':im.name,'center':im.projection_center().tolist(),'rotation':pose.rotation.matrix().T.tolist(),'size':[r.cameras[im.camera_id].width,r.cameras[im.camera_id].height],'observations':[pt.xy.tolist() for pt in im.points2D if pt.has_point3D()]})
 result.append(summary)
(out/'reconstruction.json').write_text(json.dumps(result,separators=(',',':')))
print('Exported',len(result),'runs')
