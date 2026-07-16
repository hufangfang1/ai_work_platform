<?php

namespace app\service\AiDev;

use think\facade\Db;

class ReleaseDocService
{
    const DONE_STATUSES = ['committed', 'retrospected'];

    public function get($requirementId)
    {
        $row = Db::name('ai_dev_release_docs')
            ->where('requirement_id', (int) $requirementId)
            ->order('id', 'desc')
            ->find();
        if ($row) {
            $row['project_entries'] = $this->decodeJson($row['project_entries_json'] ?? '');
        }
        return $row;
    }

    public function generate($requirementId)
    {
        $requirement = Db::name('ai_dev_requirements')->where('id', (int) $requirementId)->find();
        if (!$requirement) {
            throw new \RuntimeException('需求不存在');
        }
        $tasks = $this->tasks((int) $requirementId);
        if (!$tasks) {
            throw new \RuntimeException('需求还没有项目工单，无法生成上线文档');
        }

        $unfinished = [];
        $completed = 0;
        foreach ($tasks as $task) {
            if ($task['status'] === 'terminated') {
                continue;
            }
            if (!in_array($task['status'], self::DONE_STATUSES, true)) {
                $unfinished[] = ($task['project_name'] ?: ('project#' . $task['project_id'])) . '（' . $task['status'] . '）';
            } else {
                $completed++;
            }
        }
        if ($unfinished) {
            throw new \RuntimeException('以下项目尚未完成提交，不能生成上线文档：' . implode('、', $unfinished));
        }
        if ($completed === 0) {
            throw new \RuntimeException('需求没有已提交的项目，无法生成上线文档');
        }

        $branch = trim((string) ($requirement['final_branch_name'] ?? ''));
        $context = $this->buildRequirementContext((int) $requirementId);
        $entries = [];
        foreach ($tasks as $task) {
            if ($task['status'] === 'terminated') {
                continue;
            }
            $entries[] = $this->summarizeProject($task, $branch, $context);
        }
        return [
            'content' => $this->render($requirement, $entries),
            'project_entries' => $entries,
        ];
    }

    public function save($requirementId, $content, array $projectEntries = [])
    {
        if (!Db::name('ai_dev_requirements')->where('id', (int) $requirementId)->find()) {
            throw new \RuntimeException('需求不存在');
        }
        if (trim((string) $content) === '') {
            throw new \RuntimeException('上线文档内容不能为空');
        }
        $now = date('Y-m-d H:i:s');
        $data = [
            'content' => (string) $content,
            'project_entries_json' => json_encode($projectEntries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ];
        $existing = $this->get((int) $requirementId);
        if ($existing) {
            Db::name('ai_dev_release_docs')->where('id', $existing['id'])->update($data);
            $id = $existing['id'];
        } else {
            $data['requirement_id'] = (int) $requirementId;
            $data['created_by'] = 0;
            $data['created_at'] = $now;
            $id = Db::name('ai_dev_release_docs')->insertGetId($data);
        }
        return Db::name('ai_dev_release_docs')->where('id', $id)->find();
    }

    private function tasks($requirementId)
    {
        return Db::name('ai_dev_tasks')->alias('t')
            ->leftJoin('ai_dev_projects p', 'p.id = t.project_id')
            ->where('t.requirement_id', $requirementId)
            ->field('t.*, p.name as project_name, p.local_path as project_local_path, p.test_command, p.lint_command, p.build_command')
            ->order('t.id', 'asc')->select()->toArray();
    }

    private function buildRequirementContext($requirementId)
    {
        $doc = Db::name('ai_dev_requirement_docs')
            ->where('requirement_id', (int) $requirementId)
            ->order('version', 'desc')
            ->find();
        $breakdown = Db::name('ai_dev_breakdowns')
            ->where('requirement_id', (int) $requirementId)
            ->order('version', 'desc')
            ->select()
            ->toArray();
        $confirmedBreakdown = null;
        foreach ($breakdown as $row) {
            if (!empty($row['confirmed_at'])) {
                $confirmedBreakdown = $row;
                break;
            }
        }
        if (!$confirmedBreakdown && $breakdown) {
            $confirmedBreakdown = $breakdown[0];
        }
        $projectSpecs = [];
        if ($confirmedBreakdown) {
            foreach ($this->decodeJson($confirmedBreakdown['projects_json'] ?? '') as $item) {
                $projectId = (int) ($item['project_id'] ?? 0);
                $spec = trim((string) ($item['spec_markdown'] ?? ''));
                if ($projectId > 0 && $spec !== '') {
                    $projectSpecs[$projectId] = $spec;
                }
            }
        }
        return [
            'requirement_doc' => $doc ? (string) ($doc['content'] ?? '') : '',
            'project_specs' => $projectSpecs,
        ];
    }

    private function documentSourcesForTask(array $task, array $context)
    {
        $sources = [];
        $plan = Db::name('ai_dev_plans')->where('task_id', (int) $task['id'])->order('version', 'desc')->find();
        $planContent = $plan ? trim((string) ($plan['plan_content'] ?? '')) : '';
        if ($planContent !== '') {
            $sources[] = ['label' => '开发计划', 'content' => $planContent];
        }
        $spec = trim((string) ($task['spec_markdown'] ?? ''));
        if ($spec !== '') {
            $sources[] = ['label' => '项目需求文档', 'content' => $spec];
        }
        $projectId = (int) ($task['project_id'] ?? 0);
        if ($projectId > 0 && !empty($context['project_specs'][$projectId])) {
            $sources[] = ['label' => '拆解规格', 'content' => (string) $context['project_specs'][$projectId]];
        }
        $requirementDoc = trim((string) ($context['requirement_doc'] ?? ''));
        if ($requirementDoc !== '') {
            $sources[] = ['label' => '需求文档', 'content' => $requirementDoc];
        }
        return $sources;
    }

    private function summarizeProject(array $task, $requirementBranch, array $context)
    {
        $taskId = (int) $task['id'];
        $changes = Db::name('ai_dev_changes')->where('task_id', $taskId)->order('id', 'desc')->select()->toArray();
        $plan = Db::name('ai_dev_plans')->where('task_id', $taskId)->order('version', 'desc')->find();

        $files = [];
        $diffs = [];
        foreach ($changes as $change) {
            foreach ($this->decodeJson($change['changed_files'] ?? '') as $file) {
                if (is_string($file) && $file !== '' && !in_array($file, $files, true)) {
                    $files[] = $file;
                }
            }
            $diff = trim((string) ($change['git_diff_snapshot'] ?? ''));
            if ($diff !== '') {
                $diffs[] = $diff;
            }
        }

        $branch = trim((string) ($task['final_branch_name'] ?? ''));
        if ($branch === '') {
            $branch = $requirementBranch;
        }

        $planContent = $plan ? (string) ($plan['plan_content'] ?? '') : '';
        $documents = $this->documentSourcesForTask($task, $context);
        return [
            'task_id' => $taskId,
            'project_id' => (int) $task['project_id'],
            'project_name' => $task['project_name'] ?: ('project#' . $task['project_id']),
            'branch' => $branch ?: '未记录',
            'sql' => $this->extractSql($task, $files, $diffs, $documents),
            'env' => $this->extractEnv($files, $diffs, $documents),
            'scripts' => $this->extractScripts($files, $diffs, $documents, $task),
        ];
    }

    private function extractSql(array $task, array $files, array $diffs, array $documents)
    {
        $items = [];
        foreach ($diffs as $diff) {
            foreach ($this->extractSchemaSqlFromDiff($diff) as $statement) {
                $this->appendUnique($items, $statement);
            }
            foreach ($this->extractSchemaSqlFromModelDiff($diff) as $statement) {
                $this->appendUnique($items, $statement);
            }
        }
        foreach ($this->extractSchemaSqlFromBranch($task) as $statement) {
            $this->appendUnique($items, $statement);
        }
        foreach ($this->extractSchemaSqlFromDocuments($documents) as $statement) {
            $this->appendUnique($items, $statement);
        }
        return array_slice($this->normalizeSchemaSqlItems($items), 0, 30);
    }

    private function extractSchemaSqlFromDocuments(array $documents)
    {
        $items = [];
        $sqlKeywords = ['sql', '表结构', 'ddl', '数据库变更', '数据库', 'migration', '迁移', '数据设计'];
        foreach ($documents as $document) {
            $content = (string) ($document['content'] ?? '');
            foreach ($this->extractCodeBlocks($content, ['sql']) as $block) {
                foreach ($this->extractSchemaStatementsFromText($block) as $statement) {
                    $this->appendUnique($items, $statement);
                }
            }
            foreach ($this->extractDocumentSectionLines($content, $sqlKeywords) as $line) {
                if ($this->looksLikePlanSchemaChange($line)) {
                    $this->appendUnique($items, $line);
                }
            }
            if (preg_match_all('/`((?:database\/)?[\w\/\-\.]+\.sql)`/i', $content, $matches)) {
                foreach ($matches[1] as $path) {
                    $this->appendUnique($items, '见 ' . $path);
                }
            }
            if (($document['label'] ?? '') === '需求文档' && $this->shouldUseRequirementSchemaDoc($documents)) {
                foreach ($this->extractSchemaDesignFromRequirementDoc($content) as $line) {
                    $this->appendUnique($items, $line);
                }
            }
        }
        return $items;
    }

    private function extractSchemaDesignFromRequirementDoc($content)
    {
        $items = [];
        foreach ($this->extractDocumentSectionLines((string) $content, ['数据设计', '表结构', '数据库']) as $line) {
            if ($this->looksLikePlanSchemaChange($line) && preg_match('/`[\w]{4,}`/u', $line)) {
                $this->appendUnique($items, $line);
            }
        }
        if (preg_match_all('/###\s*8\.\d+.*?(?=###\s*8\.\d+|##\s+\d+\.|\z)/s', (string) $content, $sections)) {
            foreach ($sections[0] as $section) {
                $summary = $this->summarizeRequirementSchemaSection($section);
                if ($summary !== '') {
                    $this->appendUnique($items, $summary);
                }
            }
        }
        return $items;
    }

    private function shouldUseRequirementSchemaDoc(array $documents)
    {
        foreach ($documents as $document) {
            if (!in_array($document['label'] ?? '', ['开发计划', '项目需求文档', '拆解规格'], true)) {
                continue;
            }
            $content = (string) ($document['content'] ?? '');
            if (preg_match('/database\/|\.sql|migration|表结构|DDL|Model\.php|新增表/u', $content)) {
                return true;
            }
        }
        return false;
    }

    private function summarizeRequirementSchemaSection($section)
    {
        $table = '';
        if (preg_match('/建议新增表[：:]\s*\n+```(?:text|sql)?\s*\n([\w]+)\s*\n```/s', (string) $section, $match)) {
            $table = trim($match[1]);
        } elseif (preg_match('/##\s*[\d.]+\s+.*`([\w]+)`/u', (string) $section, $match)) {
            $table = trim($match[1]);
        }
        if ($table === '' || strlen($table) < 4) {
            return '';
        }
        $fields = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $section) as $line) {
            if (preg_match('/^\|\s*([\w]+)\s*\|\s*([^|]+)\|/', trim($line), $match) && strtolower($match[1]) !== '字段') {
                $fields[] = trim($match[1]) . ' ' . trim($match[2]);
            }
        }
        if (!$fields) {
            return '表 `' . $table . '`（见需求文档数据设计）';
        }
        return '表 `' . $table . '`：' . implode('、', array_slice($fields, 0, 8)) . (count($fields) > 8 ? ' 等' : '');
    }

    private function extractSchemaStatementsFromText($text)
    {
        $items = [];
        $buffer = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($this->looksLikeSchemaSql($line) || $buffer) {
                $buffer[] = $line;
                if (substr(rtrim($line), -1) === ';') {
                    $statement = $this->finalizeSchemaSqlBuffer($buffer);
                    if ($statement !== '') {
                        $this->appendUnique($items, $statement);
                    }
                    $buffer = [];
                }
            }
        }
        if ($buffer) {
            $statement = $this->finalizeSchemaSqlBuffer($buffer);
            if ($statement !== '') {
                $this->appendUnique($items, $statement);
            }
        }
        return $items;
    }

    private function extractEnv(array $files, array $diffs, array $documents)
    {
        $items = [];
        foreach ($files as $file) {
            if ($this->isEnvFile($file)) {
                $this->appendUnique($items, $file);
            }
        }
        foreach ($diffs as $diff) {
            foreach ($this->extractAddedLines($diff) as $line) {
                if ($this->looksLikeEnv($line)) {
                    $this->appendUnique($items, $line);
                }
            }
        }
        $envKeywords = ['env', '环境变量', '配置项', '.env', '配置变更', '环境配置'];
        foreach ($documents as $document) {
            $content = (string) ($document['content'] ?? '');
            foreach ($this->extractCodeBlocks($content, ['env', 'dotenv', 'bash', 'sh']) as $block) {
                foreach (preg_split('/\r\n|\r|\n/', $block) as $line) {
                    $line = trim($line);
                    if ($this->looksLikeEnv($line)) {
                        $this->appendUnique($items, $line);
                    }
                }
            }
            foreach ($this->extractDocumentSectionLines($content, $envKeywords) as $line) {
                if ($this->looksLikeEnvNote($line)) {
                    $this->appendUnique($items, $line);
                }
            }
        }
        return array_slice($items, 0, 30);
    }

    private function extractScripts(array $files, array $diffs, array $documents, array $task)
    {
        $items = [];
        foreach ($files as $file) {
            if (preg_match('/\.(sh|bash|ps1)$/i', $file) || preg_match('/(scripts?|bin|cron|command)\//i', $file)) {
                $this->appendUnique($items, $file);
            }
        }
        $scriptKeywords = [
            '脚本', '定时任务', '定时', 'cron', 'crontab', '队列', 'worker', 'consumer',
            '任务调度', '命令行', '需执行', '需跑',
        ];
        foreach ($documents as $document) {
            $content = (string) ($document['content'] ?? '');
            foreach ($this->extractDocumentSectionLines($content, $scriptKeywords) as $line) {
                foreach ($this->extractRunnableScriptSentences($line) as $sentence) {
                    $this->appendUnique($items, $sentence);
                }
            }
            foreach ($this->extractDocumentSectionLines($content, ['配置变更']) as $line) {
                foreach ($this->extractRunnableScriptSentences($line) as $sentence) {
                    $this->appendUnique($items, $sentence);
                }
            }
            foreach ($this->extractCodeBlocks($content, ['bash', 'sh', 'shell']) as $block) {
                foreach (preg_split('/\r\n|\r|\n/', $block) as $line) {
                    $line = trim($line);
                    if ($line !== '' && $this->looksLikeRunnableScriptNote($line)) {
                        $this->appendUnique($items, $line);
                    }
                }
            }
        }
        foreach ($diffs as $diff) {
            foreach ($this->extractAddedLines($diff) as $line) {
                if ($this->looksLikeRunnableScriptNote($line)) {
                    $this->appendUnique($items, $line);
                }
            }
        }
        return array_slice($items, 0, 30);
    }

    private function render(array $requirement, array $entries)
    {
        $content = '# ' . $requirement['title'] . " 上线文档\n\n";
        $content .= '需求分支：' . (trim((string) ($requirement['final_branch_name'] ?? '')) ?: '未记录') . "\n\n";
        foreach ($entries as $item) {
            $content .= '## ' . $item['project_name'] . "\n\n";
            $content .= '- 项目：' . $item['project_name'] . "\n";
            $content .= '- 分支：' . $item['branch'] . "\n";
            $content .= "- SQL（表结构变更）：\n" . $this->renderIndentedList($item['sql'], '未识别到表结构变更 SQL；请人工补充') . "\n";
            $content .= "- env：\n" . $this->renderIndentedList($item['env'], '文档与改动中未识别到环境变量变更；请人工补充') . "\n";
            $content .= "- 脚本（定时任务/需执行程序）：\n" . $this->renderIndentedList($item['scripts'], '未识别到需执行的脚本或定时任务；请人工补充') . "\n\n";
        }
        return $content;
    }

    private function renderIndentedList(array $items, $empty)
    {
        if (!$items) {
            return '  - ' . $empty;
        }
        return '  - ' . implode("\n  - ", array_map(function ($item) {
            return str_replace(["\r", "\n"], [' ', ' '], (string) $item);
        }, $items));
    }

    private function isSqlFile($file)
    {
        return (bool) preg_match('/\.sql$/i', $file)
            || (bool) preg_match('/(^|\/)database\//i', $file)
            || (bool) preg_match('/(migrations?|database\/migrations?)\//i', $file);
    }

    private function isEnvFile($file)
    {
        return (bool) preg_match('/(^|\/)\.env(\.|$)/i', $file)
            || (bool) preg_match('/config\/.*\.(php|yaml|yml|json)$/i', $file);
    }

    private function looksLikeSchemaSql($line)
    {
        $text = trim((string) $line);
        if ($text === '') {
            return false;
        }
        if (preg_match('/\b(INSERT|UPDATE|DELETE|SELECT|REPLACE)\b/i', $text)) {
            return false;
        }
        return (bool) preg_match(
            '/\b(CREATE\s+(TABLE|INDEX|UNIQUE\s+INDEX)|ALTER\s+TABLE|DROP\s+(TABLE|INDEX)|RENAME\s+TABLE|ADD\s+(COLUMN|INDEX|KEY|CONSTRAINT)|MODIFY\s+COLUMN|CHANGE\s+COLUMN|DROP\s+COLUMN)\b/i',
            $text
        );
    }

    private function extractSchemaSqlFromDiff($diff)
    {
        $statements = [];
        $sections = preg_split('/\n(?=diff --git )/u', (string) $diff) ?: [(string) $diff];
        foreach ($sections as $section) {
            if (!preg_match('/^\+\+\+ b\/(.+)$/m', $section, $match)) {
                continue;
            }
            $file = trim($match[1]);
            if (!$this->isSqlFile($file) && !preg_match('/migration/i', $file)) {
                continue;
            }
            $buffer = [];
            foreach (preg_split('/\r\n|\r|\n/', $section) as $line) {
                if (strpos($line, '+++') === 0 || strpos($line, '---') === 0 || strpos($line, '@@') === 0 || strpos($line, 'diff --git') === 0) {
                    continue;
                }
                $prefix = $line[0] ?? '';
                if ($prefix !== '+' && $prefix !== '-') {
                    if ($buffer) {
                        $statement = $this->finalizeSchemaSqlBuffer($buffer);
                        if ($statement !== '') {
                            $this->appendUnique($statements, $statement);
                        }
                        $buffer = [];
                    }
                    continue;
                }
                $value = ltrim(substr($line, 1));
                if ($value === '' || $value === '{' || $value === '}') {
                    continue;
                }
                if (preg_match('/^--/', $value) && !$buffer) {
                    continue;
                }
                if ($this->looksLikeSchemaSql($value) || $buffer) {
                    $buffer[] = $value;
                    if (substr(rtrim($value), -1) === ';') {
                        $statement = $this->finalizeSchemaSqlBuffer($buffer);
                        if ($statement !== '') {
                            $this->appendUnique($statements, $statement);
                        }
                        $buffer = [];
                    }
                }
            }
            if ($buffer) {
                $statement = $this->finalizeSchemaSqlBuffer($buffer);
                if ($statement !== '') {
                    $this->appendUnique($statements, $statement);
                }
            }
        }
        return $statements;
    }

    private function extractSchemaSqlFromBranch(array $task)
    {
        $repo = trim((string) ($task['project_local_path'] ?? ''));
        $base = trim((string) ($task['base_branch'] ?? ''));
        $branch = trim((string) ($task['final_branch_name'] ?? ''));
        if ($repo === '' || !is_dir($repo) || $base === '' || $branch === '') {
            return [];
        }
        try {
            $patch = (new GitWorktreeService())->output($repo, [
                'log',
                $base . '..' . $branch,
                '-p',
                '--',
                'database/',
                '*.sql',
            ]);
        } catch (\Throwable $e) {
            return [];
        }
        return $this->extractSchemaSqlFromDiff($patch);
    }

    private function extractSchemaSqlFromModelDiff($diff)
    {
        $items = [];
        $sections = preg_split('/\n(?=diff --git )/u', (string) $diff) ?: [(string) $diff];
        foreach ($sections as $section) {
            $isNew = strpos($section, 'new file mode') !== false;
            if (!preg_match('/^\+\+\+ b\/(.+)$/m', $section, $match)) {
                continue;
            }
            $file = trim($match[1]);
            if (!preg_match('/model\//i', $file)) {
                continue;
            }
            if ($isNew && preg_match("/protected\s+\\\$table\s*=\s*['\"]([\w]+)['\"]/", $section, $tableMatch)) {
                $this->appendUnique($items, '新增表 `' . $tableMatch[1] . '`（' . basename($file) . '）');
            }
        }
        return $items;
    }

    private function extractDocumentSectionLines($content, array $keywords)
    {
        $lines = [];
        $inSection = false;
        foreach (preg_split('/\r\n|\r|\n/', (string) $content) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (preg_match('/^#{1,4}\s+(.+)$/', $trimmed, $match)) {
                $title = mb_strtolower($match[1]);
                $inSection = false;
                foreach ($keywords as $keyword) {
                    if (mb_strpos($title, mb_strtolower($keyword)) !== false) {
                        $inSection = true;
                        break;
                    }
                }
                continue;
            }
            if (!$inSection) {
                continue;
            }
            if (preg_match('/^#{1,4}\s+/', $trimmed)) {
                break;
            }
            if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $match)) {
                $lines[] = trim($match[1]);
                continue;
            }
            if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $match)) {
                $lines[] = trim($match[1]);
                continue;
            }
            $lines[] = $trimmed;
        }
        return $lines;
    }

    private function extractCodeBlocks($content, array $languages = [])
    {
        $blocks = [];
        if (!preg_match_all('/```([^\n]*)\n(.*?)```/s', (string) $content, $matches, PREG_SET_ORDER)) {
            return $blocks;
        }
        foreach ($matches as $match) {
            $lang = strtolower(trim((string) ($match[1] ?? '')));
            $body = trim((string) ($match[2] ?? ''));
            if ($body === '') {
                continue;
            }
            if ($languages && !in_array($lang, $languages, true) && !in_array('', $languages, true)) {
                $matched = $lang === '';
                foreach ($languages as $candidate) {
                    if ($candidate !== '' && strpos($lang, $candidate) !== false) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
            }
            $blocks[] = $body;
        }
        return $blocks;
    }

    private function looksLikeEnvNote($line)
    {
        $text = trim((string) $line);
        if ($text === '') {
            return false;
        }
        if ($this->looksLikeEnv($text)) {
            return true;
        }
        return (bool) preg_match('/(环境变量|\.env|配置变更|不涉及.*环境|不新增.*环境|无需修改.*\.env)/iu', $text);
    }

    private function extractRunnableScriptSentences($line)
    {
        $text = trim((string) $line);
        if ($text === '') {
            return [];
        }
        $items = [];
        if (preg_match('/(不新增|不涉及|无需)(?:[^，,、。；]*[，,、])?([^，,、。；]*(?:定时任务|队列|脚本|worker|crontab|cron)[^，,、。；]*)/iu', $text, $match)) {
            $phrase = trim($match[1] . $match[2]);
            if ($phrase !== '') {
                $this->appendUnique($items, rtrim($phrase, '。；') . '。');
                return $items;
            }
        }
        if (preg_match_all('/[^。；]*(?:定时任务|队列(?:消费)?|执行脚本|脚本程序|crontab|cron|worker|php\s+think\s+[a-z][\w:-]*)[^。；]*/iu', $text, $matches)) {
            foreach ($matches[0] as $sentence) {
                $sentence = trim($sentence);
                if ($sentence !== '' && $this->looksLikeRunnableScriptNote($sentence) && !in_array(rtrim($sentence, '。；') . '。', $items, true)) {
                    $this->appendUnique($items, rtrim($sentence, '。；') . '。');
                }
            }
        }
        if ($items) {
            return $items;
        }
        $sentences = preg_split('/[。；]/u', $text) ?: [$text];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence !== '' && $this->looksLikeRunnableScriptNote($sentence)) {
                $this->appendUnique($items, $sentence . '。');
            }
        }
        return $items;
    }

    private function looksLikeRunnableScriptNote($line)
    {
        $text = trim((string) $line);
        if ($text === '') {
            return false;
        }
        if (preg_match('/(验证|验收|tsc-check|lint|php -l|npm run dev|实施步骤|上线顺序|手动交互)/iu', $text)) {
            return false;
        }
        if (preg_match('/(不涉及|不新增|无需|没有).*(定时任务|脚本|队列|worker)/iu', $text)) {
            return true;
        }
        if (preg_match('/\.(sh|bash|ps1)(\s|$)/i', $text)) {
            return true;
        }
        if (preg_match('/^(php\s+think\s+[a-z][\w:-]*|artisan\s+(schedule|queue|command)|supervisorctl|crontab)\b/i', $text)) {
            return true;
        }
        return (bool) preg_match(
            '/(定时任务|crontab|cron\s|队列消费|queue:work|queue:listen|worker|supervisor|消费脚本|执行脚本|脚本程序|需执行|需跑|command\/)/iu',
            $text
        );
    }

    private function looksLikePlanSchemaChange($line)
    {
        $text = trim((string) $line);
        if ($text === '') {
            return false;
        }
        if ($this->looksLikeSchemaSql($text)) {
            return true;
        }
        return (bool) preg_match(
            '/`[\w]+`.*(新增|增加|修改|建立|字段|索引|PRIMARY|KEY|表)/u',
            $text
        ) || (bool) preg_match('/^(新增|修改)\s+`?[\w]+`?/u', $text);
    }

    private function appendUnique(array &$items, $value)
    {
        $value = trim((string) $value);
        if ($value === '' || in_array($value, $items, true)) {
            return;
        }
        $items[] = $value;
    }

    private function normalizeSchemaSqlItems(array $items)
    {
        $alteredTables = [];
        $createdTables = [];
        foreach ($items as $item) {
            if (preg_match('/ALTER\s+TABLE\s+`?([\w]+)`?/i', (string) $item, $match)) {
                $alteredTables[strtolower($match[1])] = true;
            }
            if (preg_match('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([\w]+)`?/i', (string) $item, $match)) {
                $createdTables[strtolower($match[1])] = true;
            }
        }
        $hasDdl = !empty($createdTables) || !empty($alteredTables);
        $normalized = [];
        foreach ($items as $item) {
            $text = (string) $item;
            if (preg_match('/^见 database\//', $text) && $hasDdl) {
                continue;
            }
            if (preg_match('/^新增表 `([\w]+)`/', $text, $match) && isset($createdTables[strtolower($match[1])])) {
                continue;
            }
            if (preg_match('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([\w]+)`?/i', $text, $match)) {
                if (isset($alteredTables[strtolower($match[1])])) {
                    continue;
                }
            }
            if ($this->looksLikeSchemaSql($text)) {
                $this->appendUnique($normalized, $text);
                continue;
            }
            if (preg_match('/^新增表 `/', $text) || preg_match('/^见 database\//', $text) || preg_match('/^表 `/', $text) || $this->looksLikePlanSchemaChange($text)) {
                $duplicate = false;
                foreach ($normalized as $existing) {
                    if ($this->looksLikeSchemaSql($existing) && $this->planLineCoveredByDdl($text, $existing)) {
                        $duplicate = true;
                        break;
                    }
                }
                if (!$duplicate) {
                    $this->appendUnique($normalized, $text);
                }
            }
        }
        return $normalized;
    }

    private function planLineCoveredByDdl($planLine, $ddl)
    {
        if (!preg_match_all('/`([\w]+)`/', (string) $planLine, $matches)) {
            return false;
        }
        foreach ($matches[1] as $token) {
            if (stripos((string) $ddl, $token) === false) {
                return false;
            }
        }
        return true;
    }

    private function finalizeSchemaSqlBuffer(array $buffer)
    {
        $text = trim(implode(' ', array_map('trim', $buffer)));
        if ($text === '' || !$this->looksLikeSchemaSql($text)) {
            return '';
        }
        return preg_replace('/\s+/', ' ', $text);
    }

    private function looksLikeEnv($line)
    {
        return (bool) preg_match('/^[A-Z][A-Z0-9_]*\s*=/', $line)
            || (bool) preg_match("/env\(['\"][A-Z0-9_]+['\"]/i", $line);
    }

    private function extractAddedLines($diff)
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $diff) as $line) {
            if (strpos($line, '+++') === 0 || strpos($line, '---') === 0 || strpos($line, '@@') === 0) {
                continue;
            }
            if (strpos($line, '+') !== 0) {
                continue;
            }
            $value = ltrim(substr($line, 1));
            if ($value === '' || $value === '{' || $value === '}') {
                continue;
            }
            $lines[] = $value;
        }
        return $lines;
    }

    private function decodeJson($value)
    {
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
