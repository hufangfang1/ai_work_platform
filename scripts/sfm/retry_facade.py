"""Focal/initialization sensitivity analysis; same verified feature matches."""
import json,sqlite3
from pathlib import Path
import pycolmap as pc
ROOT=Path(__file__).resolve().parents[2];base=ROOT/'artifacts/sfm/static-mask'
for seed_pair in [(1,7),(7,8)]:
 ratio=.65
 out=ROOT/'artifacts/sfm'/f'facade-{seed_pair[0]}-{seed_pair[1]}';out.mkdir(exist_ok=True)
 src=sqlite3.connect(base/'database.db');dst=sqlite3.connect(out/'database.db');src.backup(dst);dst.close();src.close()
 db=pc.Database(str(out/'database.db'))
 for camera in db.read_all_cameras():
  camera.params=[max(camera.width,camera.height)*ratio,camera.width/2,camera.height/2,0]
  db.update_camera(camera)
 db.close()
 options=pc.IncrementalPipelineOptions();options.num_threads=4;options.extract_colors=False;options.min_model_size=3;options.max_num_models=10
 options.mapper.init_min_tri_angle=4;options.mapper.init_min_num_inliers=60
 options.init_image_id1,options.init_image_id2=seed_pair
 # Keep normal 30-inlier registration and reprojection filtering: do not force
 # a connected model by accepting unsupported poses.
 models=pc.incremental_mapping(str(out/'database.db'),str(base/'images'),str(out/'sparse'),options=options)
 result=[]
 for key,r in models.items():
  r.write_text(str(out/'sparse'/str(key)))
  result.append(dict(id=key,images=sorted(im.name for im in r.images.values() if im.has_pose),points=r.num_points3D(),mean_reprojection_error=r.compute_mean_reprojection_error(),mean_track_length=r.compute_mean_track_length(),tracks_3plus=sum(p.track.length()>=3 for p in r.points3D.values())))
 summary={'run':out.name,'assumed_focal_ratio':ratio,'init_min_tri_angle':4,'seed_pair':seed_pair,'init_min_num_inliers':60,'models':result}
 (out/'summary.json').write_text(json.dumps(summary,indent=2));print(json.dumps(summary),flush=True)
