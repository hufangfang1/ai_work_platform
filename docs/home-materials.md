# 院落材质来源

## 旧木门窗颜色纹理 v1

- 资源：`public/home-materials/timber-albedo-v1.png`。
- 方式：Codex 内置 image_gen，非 CLI，不使用 API key。
- 无参考图片；未使用或上传用户的私人照片。通用木表面，不是原门窗扫描。
- sRGB 颜色图与线性木纤维凹凸、旧漆粗糙度分离，避免浅色磨斑变成深坑。竖框沿木纹方向，横梁旋转 UV 顺木纹；每次重复对应横向 1m、沿纹 3m。
- 颜色资源随应用本地加载；不新增运行时接口，失败回退程序纹理。

### 最终提示词

```text
Use case: photorealistic-natural
Asset type: a single seamless square wood albedo texture for aged timber door and window joinery in a real-time rural courtyard reconstruction.
Primary request: flat scan-like photorealistic surface of decades-old dark reddish-brown stained wood, approximately a 1 metre wide by 3 metre long sample represented in a square texture. Subtle fine grain running vertically from top to bottom. Very small worn varnish flecks revealing desaturated brown-grey wood, narrow dark wood fibres, shallow small scratches, softly faded patches. Predominantly sound, old lived-in timber, not rotten or rustic decorative reclaimed wood. Quiet irregular details with no strong focal pattern.
Composition: perpendicular orthographic view; uniform flat surface filling all pixels; seamless tileable edges; vertical fibres everywhere.
Lighting: cross-polarized neutral diffuse illumination, albedo only, no directional light, no shadows, no reflections, no highlights, no vignette.
Palette: dark muted red-brown average approximately sRGB #624a3d, with low-contrast grey-brown aged areas. Not orange, not yellow, not saturated red.
Constraints: no plank seams, boards, grooves, frames, doors, handles, nails, knots larger than a tiny fleck, panels, holes, plants, people, symbols, lettering, borders or watermark. Do not generate a scene or complete object. Only the wood surface texture.
```

## 旧铁门颜色纹理 v1

- 资源：`public/home-materials/iron-gate-albedo-v1.png`（约 2.6 MB）。
- 方式：Codex 内置 image_gen，非 CLI，不使用 API key。
- 无参考图片；未上传用户原照片。这是通用漆面，不是原门扫描。
- 只作为 sRGB 颜色图；几何折边、浅弯曲和铰链独立建模，凹凸、粗糙度使用线性微表面数据，不把锈色直接当凹凸。
- 随应用本地加载，没有运行时生成请求。加载失败仍使用程序材质。

### 最终提示词

```text
Use case: photorealistic-natural
Asset type: single square tileable base-color/albedo texture for a weathered rural iron gate in a realtime 3D environment.
Primary request: scan-like photorealistic close-up of flat old sheet steel painted a faded dark reddish-brown oxblood. Approximately 1.2 metres square of material. A mostly intact, decades-old matte enamel film, uneven subtly chalky fading, fine shallow scratches, a little brown oxidation visible through small irregular paint chips, faint vertical rain streaks. Quiet everyday wear, not ruin or exaggerated industrial rust.
Composition: perpendicular orthographic view, perfectly flat surface filling every pixel, no perspective. Seamless tileable edges, no identifiable objects or focal marks.
Lighting: cross-polarized even neutral diffuse illumination; albedo only; no cast shadows, reflections, specular highlights, ambient occlusion, edge darkening or vignette.
Palette: subdued brownish burgundy base approximately sRGB #633c35; desaturated iron oxide, small gray-brown worn flecks; restrained contrast.
Constraints: no actual door outline, no frames, seams, bolts, handles, panels, planks, corrugations, lettering, characters, stickers, people, plants, background, borders, logos or watermark. One single flat square material sample, not a rendered object.
```

## 石灰墙颜色纹理 v1

- 资源：`public/home-materials/limewash-albedo-v1.png`
- 生成方式：Codex 内置 image_gen（非 CLI/API-key 模式）。
- 不使用输入图片，未上传用户的五张私人照片。
- 这是通用表面纹理，不是老家墙面的扫描结果；只用于颜色，凹凸和粗糙度仍为独立线性数据图。
- 图片随应用本地加载，运行时没有生成接口调用。使用镜像重复，以免纹理边缘出现明显跳变。

### 最终提示词

```text
Use case: photorealistic-natural
Asset type: a single square seamless albedo texture for a real-time 3D rural courtyard plaster wall, not a scene render.
Primary request: generate a photorealistic scan-like surface of aged off-white limewashed cement plaster, approximately a 4 meter by 4 meter area. Fine granular mineral surface, irregular wispy pale gray discoloration, a few subtle tiny worn spots and fine short hairline cracks. Predominantly light chalky off-white, understated years of wear, not derelict or heavily damaged.
Composition: orthographic camera perpendicular to a completely flat wall; material fills every pixel; seamless tileable left/right and top/bottom edges; non-directional gently varying texture without distinct focal points.
Lighting: diffuse cross-polarized neutral illumination, albedo only, no cast shadows, no ambient occlusion, no highlights, no gradients of lighting, no vignette.
Palette: neutral warm off-white with subtle gray-beige wear; average base color approximately sRGB #c6c4bb, keep contrast restrained.
Constraints: only plaster, no bricks, no exposed masonry, no doors, windows, objects, plants, people, graffiti, lettering, borders or watermark. No heavy cracks, no large dark stains, no repeating manmade patterns. Output one square high-detail texture.
```
