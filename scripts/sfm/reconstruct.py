"""Local-only, reproducible ten-photo SfM feasibility experiment.
Run: .venv-sfm/bin/python scripts/sfm/reconstruct.py --run baseline
Original files remain unchanged; databases/models stay in ignored artifacts/.
"""
import argparse, hashlib, json, shutil, sqlite3
from pathlib import Path
import numpy as np
from PIL import Image
import pycolmap as pc
import cv2
cv2.setNumThreads(4)
ROOT=Path(__file__).resolve().parents[2]
p=argparse.ArgumentParser();p.add_argument('--run',default='baseline');p.add_argument('--masked',action='store_true');args=p.parse_args()
out=ROOT/'artifacts/sfm'/args.run;out.mkdir(parents=True,exist_ok=True)
images=out/'images';images.mkdir(exist_ok=True)
manifest=[]
for src in sorted((ROOT/'public/home-photos').rglob('*.jpg')):
 dst=images/src.name
 if not dst.exists():shutil.copy2(src,dst)
 im=Image.open(src)
 manifest.append(dict(name=src.name,source=str(src.relative_to(ROOT)),size=im.size,sha256=hashlib.sha256(src.read_bytes()).hexdigest()))
(out/'manifest.json').write_text(json.dumps(manifest,indent=2))
reader=pc.ImageReaderOptions()
if args.masked:
 from masks import make_masks
 reader.mask_path=str(make_masks(images,out/'masks'))
db=out/'database.db'
match=pc.SiftMatchingOptions();match.num_threads=4;match.use_gpu=False;match.guided_matching=True
print('EXTRACT (OpenCV RootSIFT; COLMAP JPEG decoder unavailable on this host)',flush=True)
database=pc.Database(str(db));sift=cv2.SIFT_create(nfeatures=12000,contrastThreshold=.02)
for record in manifest:
 name=record['name']
 if database.exists_image(name):continue
 original=cv2.imread(str(images/name),cv2.IMREAD_GRAYSCALE)
 h,w=original.shape;factor=min(1,2400/max(w,h));gray=cv2.resize(original,None,fx=factor,fy=factor,interpolation=cv2.INTER_AREA)
 mask=None
 if args.masked:
  mask=cv2.imread(str(Path(reader.mask_path)/(name+'.png')),0);mask=cv2.resize(mask,(gray.shape[1],gray.shape[0]),interpolation=cv2.INTER_NEAREST)
 keys,desc=sift.detectAndCompute(gray,mask)
 camera=pc.Camera(model='SIMPLE_RADIAL',width=w,height=h,params=[1.2*max(w,h),w/2,h/2,0])
 cid=database.write_camera(camera);iid=database.write_image(pc.Image(name=name,camera_id=cid))
 points=np.array([[k.pt[0]/factor+.5,k.pt[1]/factor+.5,k.size/factor/2,np.deg2rad(k.angle)] for k in keys],dtype=np.float32)
 desc=np.sqrt(desc/np.maximum(desc.sum(axis=1,keepdims=True),1e-12));desc=np.clip(np.rint(desc*512),0,255).astype(np.uint8)
 database.write_keypoints(iid,points);database.write_descriptors(iid,desc)
 print(name,len(keys),flush=True)
database.close()
print('MATCH ALL 45 PAIRS',flush=True)
pc.match_exhaustive(str(db),sift_options=match,device=pc.Device.cpu)
con=sqlite3.connect(db);names=dict(con.execute('SELECT image_id,name FROM images'));pairs=[]
raw=dict(con.execute('SELECT pair_id,rows FROM matches'))
for pair,n,config in con.execute('SELECT pair_id,rows,config FROM two_view_geometries'):
 b=pair%2147483647;a=(pair-b)//2147483647
 pairs.append(dict(a=names[a],b=names[b],raw=raw.get(pair,0),inliers=n,configuration=config))
features=[dict(name=names[i],count=n) for i,n in con.execute('SELECT image_id,rows FROM keypoints')]
con.close()
(out/'pairs.json').write_text(json.dumps(sorted(pairs,key=lambda x:-x['inliers']),indent=2))
opts=pc.IncrementalPipelineOptions();opts.num_threads=4;opts.extract_colors=False;opts.min_model_size=3;opts.max_num_models=10
print('MAP',flush=True)
models=pc.incremental_mapping(str(db),str(images),str(out/'sparse'),options=opts)
summary={'pycolmap':pc.__version__,'run':args.run,'masked':args.masked,'features':features,'models':[]}
for key,r in models.items():
 # Recover observed point colours through OpenCV, bypassing the host decoder.
 cache={}
 for point in r.points3D.values():
  samples=[]
  for el in point.track.elements:
   im=r.images[el.image_id]
   if im.name not in cache:cache[im.name]=cv2.imread(str(images/im.name))
   xy=im.points2D[el.point2D_idx].xy;bitmap=cache[im.name];xx=int(round(xy[0]-.5));yy=int(round(xy[1]-.5))
   if 0<=xx<bitmap.shape[1] and 0<=yy<bitmap.shape[0]:samples.append(bitmap[yy,xx,::-1])
  if samples:point.color=np.median(samples,axis=0).astype(np.uint8)
 target=out/'sparse'/str(key);target.mkdir(exist_ok=True,parents=True);r.write(str(target));r.write_text(str(target));r.export_PLY(str(target/'points.ply'))
 pts=list(r.points3D.values());registered=sorted(im.name for im in r.images.values() if im.has_pose)
 summary['models'].append(dict(id=key,images=registered,points=len(pts),mean_reprojection_error=r.compute_mean_reprojection_error(),mean_track_length=r.compute_mean_track_length(),tracks_3plus=sum(pt.track.length()>=3 for pt in pts)))
(out/'summary.json').write_text(json.dumps(summary,indent=2))
print(json.dumps(summary,indent=2),flush=True)
