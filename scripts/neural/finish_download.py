from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
import requests,json,shutil
root=Path(__file__).resolve().parents[2]/'artifacts/neural';manifest=json.loads((root/'download-ranges.json').read_text());jobs=[]
for i,a,b in manifest['ranges']:
 part=root/f'weights.part{i}';have=part.stat().st_size;cursor=a+have;k=0
 while cursor<=b:
  end=min(cursor+64*1024*1024-1,b);jobs.append((i,k,cursor,end));cursor=end+1;k+=1
def get(job):
 i,k,a,b=job;path=root/f'tail-{i}-{k}'
 r=requests.get(f'https://huggingface.co/facebook/VGGT-1B/resolve/main/model.pt?download=true&tail={a}',headers={'Range':f'bytes={a}-{b}'},stream=True,timeout=60);r.raise_for_status();assert r.status_code==206 and r.headers['Content-Range'].startswith(f'bytes {a}-{b}/')
 with path.open('wb') as f:
  for chunk in r.iter_content(1024*1024):f.write(chunk)
 assert path.stat().st_size==b-a+1
 print('tail done',i,k,flush=True);return i,k,path
with ThreadPoolExecutor(max_workers=4) as pool:done=list(pool.map(get,jobs))
for i,k,path in sorted(done):
 with (root/f'weights.part{i}').open('ab') as f,path.open('rb') as src:shutil.copyfileobj(src,f)
 path.unlink()
with (root/'model.pt').open('ab') as f:
 for i,a,b in manifest['ranges']:
  path=root/f'weights.part{i}';assert path.stat().st_size==b-a+1
  with path.open('rb') as src:shutil.copyfileobj(src,f)
assert (root/'model.pt').stat().st_size==manifest['total']
for i,a,b in manifest['ranges']:(root/f'weights.part{i}').unlink()
print('COMPLETE',flush=True)
