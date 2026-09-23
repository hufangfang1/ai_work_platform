# 回家 · 记忆里的院子

根据五张私人老照片手工搭建的可行走三维院子。它是照片参考的近似空间重建，不是自动扫描；未拍到的结构与尺寸仍需照片所有者校正。

## 本地运行

```bash
npm install
npm run dev
```

打开 `http://localhost:6173/`。拖动环顾，使用 WASD、方向键或屏幕按钮行走；也可切换参考视点、俯看院子，并对照五张原照片。

验证：`npm test`、`npm run build`。

实现与推断记录见 [重建说明](docs/childhood-home.md)，材质记录见 [材质说明](docs/home-materials.md)。

**隐私：`public/home-photos/` 含私人照片，仅供本地预览。公开部署会同时发布这些文件；部署前须取得授权或移除照片。**
