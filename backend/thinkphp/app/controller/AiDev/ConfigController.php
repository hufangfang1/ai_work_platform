<?php

namespace app\controller\AiDev;

use app\service\AiDev\ConfigService;
use app\service\AiDev\MigrationService;
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

    public function saveModel(ConfigService $service)
    {
        return $this->ok($service->saveModel($this->request->put()));
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
