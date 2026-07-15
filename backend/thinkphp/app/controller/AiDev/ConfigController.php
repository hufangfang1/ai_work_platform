<?php

namespace app\controller\AiDev;

use app\service\AiDev\ConfigService;
use app\service\AiDev\MigrationService;
use app\service\AiDev\ModelProfileService;
use app\service\AiDev\WorkspaceService;

class ConfigController extends BaseController
{
    public function workspace(WorkspaceService $service)
    {
        return $this->ok(['roots' => $service->getRoots()]);
    }

    public function saveWorkspace(WorkspaceService $service)
    {
        return $this->ok(['roots' => $service->saveRoots($this->request->put('roots/a', []))]);
    }

    public function browseWorkspace(WorkspaceService $service)
    {
        return $this->ok($service->browse($this->request->get('path', '')));
    }

    public function model(ConfigService $service)
    {
        return $this->ok($service->model());
    }

    public function modelProfiles(ConfigService $service)
    {
        return $this->ok($service->modelProfiles());
    }

    /** 可选模型清单 + 每步默认模型,供前端各 AI 按钮旁的模型下拉框使用 */
    public function modelOptions(ModelProfileService $service)
    {
        return $this->ok([
            'models' => $service->available(),
            'step_defaults' => $service->stepDefaults(),
        ]);
    }

    public function saveModel(ConfigService $service)
    {
        return $this->ok($service->saveModel($this->request->put()));
    }

    public function saveModelProfiles(ConfigService $service)
    {
        return $this->ok($service->saveModelProfiles($this->request->put('profiles/a', [])));
    }

    public function securityRules(ConfigService $service)
    {
        return $this->ok($service->securityRules());
    }

    public function saveSecurityRules(ConfigService $service)
    {
        return $this->ok($service->saveSecurityRules($this->request->put('rules/a', [])));
    }

    public function exportConfig(ConfigService $service)
    {
        return $this->ok($service->exportConfig());
    }

    public function importConfig(ConfigService $service)
    {
        return $this->ok($service->importConfig($this->request->post()));
    }

    public function migrationStatus(MigrationService $service)
    {
        return $this->ok($service->status());
    }

    public function migrate(MigrationService $service)
    {
        return $this->ok($service->migrate());
    }
}
