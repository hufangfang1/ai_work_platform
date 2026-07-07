<?php

namespace app\command;

use app\service\AiDev\MigrationService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class AiDevMigrate extends Command
{
    protected function configure()
    {
        $this->setName('ai-dev:migrate')->setDescription('执行 AI 开发工作台数据库迁移');
    }

    protected function execute(Input $input, Output $output)
    {
        $result = (new MigrationService())->migrate();
        if (empty($result['applied'])) {
            $output->writeln('No pending migrations.');
            return 0;
        }
        foreach ($result['applied'] as $name) {
            $output->writeln('Applied: ' . $name);
        }
        return 0;
    }
}
