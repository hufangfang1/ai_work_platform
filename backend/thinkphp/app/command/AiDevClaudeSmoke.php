<?php

namespace app\command;

use app\service\AiDev\ClaudeCliService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class AiDevClaudeSmoke extends Command
{
    protected function configure()
    {
        $this->setName('ai-dev:claude-smoke')->setDescription('验证 claude CLI headless 调用可用');
    }

    protected function execute(Input $input, Output $output)
    {
        $service = new ClaudeCliService();
        $data = $service->runJson('只返回 JSON,不要任何其他内容:{"ok":true}', ['timeout' => 120, 'max_turns' => 1]);
        $output->writeln(json_encode($data));
        return empty($data['ok']) ? 1 : 0;
    }
}
