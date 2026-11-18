<?php
// A small executable contract suite; no MODX installation or database required.
namespace MODX\Revolution {
    class modX
    {
        public const LOG_LEVEL_ERROR = 1;
        public array $rows = [];
        public array $typeRows = [['id'=>1, 'code'=>'exam', 'name'=>'Экзамен'], ['id'=>2, 'code'=>'check', 'name'=>'Проверка']];
        public array $calls = [];
        public bool $fail = false;
        public bool $committed = false;
        public bool $rolledBack = false;
        public array $chunks = [];
        public function getChunk($name, array $properties = []) {
            $path = dirname(__DIR__) . '/core/components/scheduledates/elements/chunks/' . $name . '.tpl';
            $template = $this->chunks[$name] ?? (is_file($path) ? file_get_contents($path) : '');
            return preg_replace_callback('/\[\[\+([a-zA-Z]+)\]\]/', static function ($match) use ($properties) { return $properties[$match[1]] ?? ''; }, $template);
        }
        public function getOption($key, $options = null, $default = null) { return $options[$key] ?? ['table_prefix'=>'modx_', 'assets_url'=>'/assets/'][$key] ?? $default; }
        public function prepare($sql) { $this->calls[] = $sql; return new \Statement($this, $sql); }
        public function beginTransaction() { return true; }
        public function commit() { return $this->committed = true; }
        public function rollBack() { return $this->rolledBack = true; }
        public function regClientCSS($path) { $this->calls[] = $path; }
        public function log($level, $message) { throw new \RuntimeException($message); }
    }
}
namespace {
    class Statement
    {
        private $modx;
        private $sql;
        private $params;
        public function __construct($modx, $sql) { $this->modx = $modx; $this->sql = $sql; }
        public function execute($params) { $this->params = $params; $this->modx->calls[] = $params; return !$this->modx->fail; }
        public function fetchAll($mode) { return strpos($this->sql, 'SELECT id,code,name') === 0 ? $this->modx->typeRows : $this->modx->rows; }
        public function fetchColumn() {
            foreach ($this->modx->typeRows as $type) {
                if (isset($this->params[':type']) && $type['code'] === $this->params[':type']) return $type['code'];
                if (isset($this->params[':code']) && $type['code'] === $this->params[':code'] && $type['id'] != $this->params[':id']) return $type['id'];
                if (strpos($this->sql, 'SELECT code') === 0 && isset($this->params[':id']) && $type['id'] == $this->params[':id']) return $type['code'];
            }
            return false;
        }
    }
    require dirname(__DIR__) . '/core/components/scheduledates/src/Schedule.php';
    define('MODX_CORE_PATH', dirname(__DIR__) . '/core/');
    $checks = 0;
    function check($condition, $message) {
        global $checks;
        $checks++;
        if (!$condition) throw new \RuntimeException($message);
    }
    function rejects(callable $callback) {
        try { $callback(); } catch (\InvalidArgumentException $error) { return true; }
        return false;
    }
    check(\ScheduleDates\Schedule::date('2028-02-29') === '2028-02-29', 'Leap day');
    foreach (['2027-02-29', '2027-04-31', '2027-1-01', '2027-01-01<script>', '1899-12-31'] as $date) {
        check(rejects(static function () use ($date) { \ScheduleDates\Schedule::date($date); }), 'Reject invalid date: ' . $date);
    }
    check(rejects(static function () { \ScheduleDates\Schedule::year('2027 OR 1=1'); }), 'Reject SQL-like year');
    $modx = new \MODX\Revolution\modX();
    $service = new \ScheduleDates\Schedule($modx);
    $input = ['title'=>'Экзамен', 'type'=>'exam', 'dates'=>['2027-01-01', '2027-01-01', '2027-12-31'], 'active'=>1];
    check($service->save($input) === 2, 'Deduplicate selected dates');
    check($modx->committed, 'Commit bulk save');
    check(count($modx->calls) === 5, 'Type lock and one prepared insert for two dates');
    check($modx->calls[3][':title'] === 'Экзамен', 'Bound title');
    check(rejects(static function () use ($service, $input) { $service->save(array_merge($input, ['title'=>' '])); }), 'Reject blank title');
    check(rejects(static function () use ($service, $input) { $service->save(array_merge($input, ['type'=>'unknown'])); }), 'Reject unknown type');
    check(rejects(static function () use ($service, $input) { $service->save(array_merge($input, ['dates'=>[]])); }), 'Reject empty selection');
    check(rejects(static function () use ($service, $input) { $service->save(array_merge($input, ['id'=>7])); }), 'Edit accepts one date only');
    $modx->fail = true;
    try { $service->save($input); } catch (\RuntimeException $error) {}
    check($modx->rolledBack, 'Rollback on database error');
    $modx->fail = false;
    $modx->calls = [];
    $service->list(2027, 'check', true);
    check(strpos($modx->calls[0], 'active = 1') !== false, 'Only published events');
    check($modx->calls[1] === [':start'=>'2027-01-01', ':end'=>'2028-01-01', ':type'=>'check'], 'Year and type boundaries');
    $modx->rows = [['id'=>1, 'event_date'=>'2027-02-01', 'title'=>'<script>[[danger]]</script>', 'type'=>'exam', 'description'=>'<b>text</b>', 'active'=>1]];
    $scriptProperties = ['year'=>2027];
    $html = include dirname(__DIR__) . '/core/components/scheduledates/elements/snippets/scheduledates.php';
    check(strpos($html, 'Февраль 2027') !== false, 'Month grouping');
    check(strpos($html, 'понедельник') !== false, 'Weekday');
    check(strpos($html, '<script>') === false && strpos($html, '[[danger]]') === false, 'Escape HTML and MODX tags');
    check(strpos($html, '&lt;b&gt;text&lt;/b&gt;') !== false, 'Description is plain text');
    $scriptProperties = ['year'=>'', 'emptyText'=>'Нет дат'];
    $modx->rows = [];
    $html = include dirname(__DIR__) . '/core/components/scheduledates/elements/snippets/scheduledates.php';
    check(strpos($html, 'Нет дат') !== false, 'Default year and empty state');
    $modx->rows = [
        ['id'=>1, 'event_date'=>'2027-02-01', 'title'=>'Первый "экзамен" [[danger]]', 'type'=>'exam', 'description'=>'', 'active'=>1],
        ['id'=>2, 'event_date'=>'2027-02-02', 'title'=>'Второй', 'type'=>'exam', 'description'=>'', 'active'=>1],
        ['id'=>3, 'event_date'=>'2027-03-01', 'title'=>'Третий', 'type'=>'check', 'description'=>'', 'active'=>1],
    ];
    $scriptProperties = ['year'=>2027, 'tpl'=>'scheduledatesOption', 'tplWrapper'=>'scheduledatesSelect', 'groupByMonth'=>0, 'css'=>0];
    $html = include dirname(__DIR__) . '/core/components/scheduledates/elements/snippets/scheduledates.php';
    check(substr_count($html, '<option ') === 4 && substr_count($html, '<select ') === 1, 'Select wrapper and all three dates');
    check(strpos($html, '<section') === false && strpos($html, '<li') === false, 'No calendar markup in select');
    check(strpos($html, 'value="2027-02-01"') !== false && strpos($html, '01.02.2027') !== false, 'Option date value and label');
    check(strpos($html, '&quot;экзамен&quot; &#91;&#91;danger&#93;&#93;') !== false, 'Safe custom option text');
    $modx->chunks['testRow'] = '<b>[[+title]]</b>';
    $modx->chunks['testMonth'] = '<article data-month="[[+month]]">[[+monthName]]:[[+count]][[+output]]</article>';
    $modx->chunks['testWrapper'] = '<main data-count="[[+count]]">[[+output]]</main>';
    $scriptProperties = ['year'=>2027, 'tpl'=>'testRow', 'tplMonth'=>'testMonth', 'tplWrapper'=>'testWrapper'];
    $html = include dirname(__DIR__) . '/core/components/scheduledates/elements/snippets/scheduledates.php';
    check(substr_count($html, '<article ') === 2 && strpos($html, 'Февраль:2') !== false, 'Custom month grouping');
    check(strpos($html, '<main data-count="3">') !== false, 'Custom wrapper total');
    check(strpos($html, '<b>Второй</b>') !== false, 'Custom row rendering');
    $modx->rows = [];
    $scriptProperties = ['year'=>2027, 'tpl'=>'scheduledatesOption', 'tplWrapper'=>'scheduledatesSelect', 'groupByMonth'=>0];
    $html = include dirname(__DIR__) . '/core/components/scheduledates/elements/snippets/scheduledates.php';
    check(strpos($html, '<p') === false && substr_count($html, '<option ') === 1, 'Valid empty select');
    $modx->chunks['emptyWrapper'] = '<div>[[+count]]:[[+emptyText]][[+output]]</div>';
    $scriptProperties = ['year'=>2027, 'tplWrapper'=>'emptyWrapper', 'emptyText'=>'Нет <дат>'];
    $html = include dirname(__DIR__) . '/core/components/scheduledates/elements/snippets/scheduledates.php';
    check($html === '<div>0:Нет &lt;дат&gt;</div>', 'Custom empty wrapper placeholders');
    check(\ScheduleDates\Schedule::typeCode('attestation-1') === 'attestation-1', 'Custom type code');
    foreach (['', 'EXAM', 'тип', '1exam', 'exam space', str_repeat('a', 21)] as $code) {
        check(rejects(static function () use ($code) { \ScheduleDates\Schedule::typeCode($code); }), 'Reject invalid type code');
    }
    $service->saveType(['name'=>'Аттестация', 'code'=>'attestation']);
    check($modx->committed, 'Custom type creation commits');
    check(rejects(static function () use ($service) { $service->saveType(['name'=>'Дубль', 'code'=>'exam']); }), 'Duplicate type code rejected');
    check(rejects(static function () use ($service) { $service->saveType(['name'=>' ', 'code'=>'attestation']); }), 'Blank type name rejected');
    $modx->typeRows[] = ['id'=>3, 'code'=>'attestation', 'name'=>'Аттестация <новая>'];
    check($service->save(array_merge($input, ['type'=>'attestation'])) === 2, 'Events accept custom types');
    $modx->rows = [['id'=>4, 'event_date'=>'2027-06-01', 'title'=>'Тест', 'type'=>'attestation', 'description'=>'', 'active'=>1]];
    $scriptProperties = ['year'=>2027];
    $html = include dirname(__DIR__) . '/core/components/scheduledates/elements/snippets/scheduledates.php';
    check(strpos($html, 'Аттестация &lt;новая&gt;') !== false, 'Snippet renders escaped custom type name');
    $modx->chunks['monthEvents'] = '<article data-month="[[+month]]" data-count="[[+count]]">[[+output]]</article>';
    $modx->chunks['eventDay'] = '<span data-id="[[+id]]">[[+day]]</span>';
    $modx->rows = [
        ['id'=>1, 'event_date'=>'2027-02-02', 'title'=>'A', 'type'=>'exam', 'description'=>'', 'active'=>1],
        ['id'=>2, 'event_date'=>'2027-02-02', 'title'=>'B', 'type'=>'check', 'description'=>'', 'active'=>1],
        ['id'=>3, 'event_date'=>'2027-02-15', 'title'=>'C', 'type'=>'exam', 'description'=>'', 'active'=>1],
    ];
    $scriptProperties = ['year'=>2027, 'tpl'=>'eventDay', 'tplMonth'=>'monthEvents', 'tplWrapper'=>'testWrapper', 'showEmptyMonths'=>1];
    $html = include dirname(__DIR__) . '/core/components/scheduledates/elements/snippets/scheduledates.php';
    check(substr_count($html, '<article ') === 12, 'Show all twelve months');
    check(strpos($html, 'data-month="02" data-count="3"><span data-id="1">02</span><span data-id="2">02</span><span data-id="3">15</span></article>') !== false, 'Each event renders separately, including events sharing a date');
    check(strpos($html, 'data-month="01" data-count="0"></article>') !== false, 'Empty month placeholders');
    $modx->rows = [];
    $html = include dirname(__DIR__) . '/core/components/scheduledates/elements/snippets/scheduledates.php';
    check(substr_count($html, '<article ') === 12, 'Entirely empty year still renders all months when requested');
    $scriptProperties['groupByMonth'] = 0;
    $html = include dirname(__DIR__) . '/core/components/scheduledates/elements/snippets/scheduledates.php';
    check(strpos($html, '<article ') === false, 'Empty months ignored when grouping disabled');
    $modx->chunks['dayState'] = '<span class="day [[+dateClass]]" data-past="[[+isPast]]">[[+date]]</span>';
    $modx->chunks['plainWrapper'] = '[[+output]]';
    $referenceDay = new \DateTimeImmutable('today');
    foreach ([-1=>'is-past', 0=>'is-upcoming', 1=>'is-upcoming'] as $offset=>$expectedClass) {
        $date = $referenceDay->modify(sprintf('%+d day', $offset));
        $modx->rows = [['id'=>1, 'event_date'=>$date->format('Y-m-d'), 'title'=>'День', 'type'=>'exam', 'description'=>'', 'active'=>1]];
        $scriptProperties = ['year'=>$date->format('Y'), 'tpl'=>'dayState', 'tplWrapper'=>'plainWrapper', 'groupByMonth'=>0, 'css'=>0];
        $html = include dirname(__DIR__) . '/core/components/scheduledates/elements/snippets/scheduledates.php';
        check(strpos($html, 'class="day ' . $expectedClass . '"') !== false, 'Date class for relative day ' . $offset);
        check(strpos($html, 'data-past="' . ($offset < 0 ? 1 : 0) . '"') !== false, 'isPast flag for relative day ' . $offset);
    }
    $modx->rows = [['id'=>1, 'event_date'=>$referenceDay->modify('-1 day')->format('Y-m-d'), 'title'=>'Вчера', 'type'=>'exam', 'description'=>'', 'active'=>1]];
    $scriptProperties['upcoming'] = 1;
    $html = include dirname(__DIR__) . '/core/components/scheduledates/elements/snippets/scheduledates.php';
    check(strpos($html, 'is-past') === false, 'Upcoming filter still excludes past rows');
    echo "Passed $checks checks.\n";
}
