<?php
/** MySQL integration check using connection-local TEMPORARY tables only. */
use MODX\Revolution\modX;
if (PHP_SAPI !== 'cli') exit('CLI only');
$installation = rtrim($argv[1] ?? '', '/\\');
require $installation . '/config.core.php';
require MODX_CORE_PATH . 'vendor/autoload.php';
require dirname(__DIR__) . '/core/components/scheduledates/src/Schedule.php';
class ScheduleDatesTemporaryModx extends modX
{
    public function exec($query) {
        return parent::exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $query));
    }
}
$modx = new ScheduleDatesTemporaryModx();
$modx->initialize('web');
$modx->setOption('table_prefix', 'sdtest_' . bin2hex(random_bytes(6)) . '_');
$service = new \ScheduleDates\Schedule($modx);
$checks = 0;
$assert = static function ($value, $message) use (&$checks) {
    $checks++;
    if (!$value) throw new RuntimeException($message);
};
$reject = static function (callable $task) {
    try { $task(); } catch (InvalidArgumentException $error) { return true; }
    return false;
};
$service->install();
$assert(array_column($service->types(), 'name', 'code') === ['exam'=>'Экзамен'], 'Only the example exam type is installed');
$assert($service->list(2027) === [], 'No example events are installed');
$service->saveType(['code'=>'attestation', 'name'=>'Аттестация']);
$type = array_values(array_filter($service->types(), static function ($type) { return $type['code'] === 'attestation'; }))[0];
$service->save(['type'=>'attestation', 'title'=>'Проверка типа', 'dates'=>['2027-02-01'], 'active'=>1]);
$assert(count($service->list(2027, 'attestation')) === 1, 'Save custom type event');
$assert($reject(static function () use ($service, $type) { $service->removeType($type['id']); }), 'Cannot delete used type');
$service->saveType(['id'=>$type['id'], 'code'=>'certification', 'name'=>'Сертификация']);
$assert(count($service->list(2027, 'certification')) === 1 && count($service->list(2027, 'attestation')) === 0, 'Rename cascades to events');
$assert($reject(static function () use ($service) { $service->saveType(['code'=>'certification', 'name'=>'Duplicate']); }), 'Duplicate code rejected');
$assert($reject(static function () use ($service) { $service->save(['type'=>'missing', 'title'=>'Unknown', 'dates'=>['2027-01-01']]); }), 'Unknown type rejected');
$event = $service->list(2027, 'certification')[0];
$service->remove($event['id']);
$service->removeType($type['id']);
$assert(count($service->types()) === 1, 'Unused type can be removed');
$service->save(['type'=>'exam', 'title'=>'Событие A', 'dates'=>['2027-11-18'], 'active'=>1]);
$service->save(['type'=>'exam', 'title'=>'Событие B', 'dates'=>['2027-11-18'], 'active'=>1]);
$before = $service->list(2027, 'exam');
$assert($reject(static function () use ($service) {
    $service->save(['id'=>4294967295, 'type'=>'exam', 'title'=>'Missing', 'dates'=>['2027-11-18']]);
}), 'Editing a deleted event is rejected instead of reporting success');
$assert($service->list(2027, 'exam') === $before, 'Missing event edit leaves the schedule unchanged');
$second = $before[1];
foreach ([PDO::ERRMODE_SILENT, PDO::ERRMODE_EXCEPTION] as $mode) {
    $modx->setAttribute(PDO::ATTR_ERRMODE, $mode);
    $message = '';
    try {
        $service->save(['id'=>$second['id'], 'type'=>'exam', 'title'=>'Событие A', 'dates'=>['2027-11-18'], 'active'=>1]);
    } catch (InvalidArgumentException $error) {
        $message = $error->getMessage();
    }
    $assert(strpos($message, 'уже есть событие') !== false, 'Editing into duplicate returns an actionable message');
    $assert($service->list(2027, 'exam') === $before, 'Duplicate edit rolls back and preserves both events');
}
$service->save(['type'=>'exam', 'title'=>'Событие A', 'dates'=>['2027-11-18'], 'description'=>'Обновлено', 'active'=>1]);
$assert(count($service->list(2027, 'exam')) === 2, 'Bulk duplicate upsert still updates without creating another event');
require dirname(__DIR__) . '/core/components/scheduledates/processors/events.class.php';
$modx->error = new \MODX\Revolution\Error\modError($modx);
$_SERVER['REQUEST_METHOD'] = 'POST';
$process = static function (array $properties) use ($modx): array {
    $result = (new ScheduleDatesEventsProcessor($modx, $properties))->process();
    return is_string($result) ? json_decode($result, true) : $result;
};
$result = $process(['operation'=>'saveType', 'code'=>'native', 'name'=>'Нативный тип']);
$assert($result['success'], 'Native type form saves');
$result = $process(['operation'=>'save', 'date_iso'=>'2027-12-12', 'title'=>'Native event', 'description'=>'Description', 'type'=>'native']);
$assert($result['success'], 'Native event form saves without JSON payload');
$row = $service->list(2027, 'native')[0];
$assert((int)$row['active'] === 0, 'Unchecked publication checkbox is saved as hidden');
$result = $process(['operation'=>'save', 'id'=>$row['id'], 'date_iso'=>'2027-12-13', 'title'=>'Native event edited', 'description'=>'Changed', 'type'=>'native', 'active'=>'1']);
$assert($result['success'] && (int)$service->list(2027, 'native')[0]['active'] === 1, 'Native edit saves date and checked publication');
$page = $process(['operation'=>'gridEvents', 'year'=>2027, 'limit'=>1, 'start'=>1]);
$assert($page['success'] && $page['total'] === 3 && count($page['results']) === 1, 'Grid pagination keeps full total');
$page = $process(['operation'=>'gridEvents', 'year'=>2027, 'query'=>'native event', 'sort'=>'title', 'dir'=>'DESC']);
$assert($page['total'] === 1 && $page['results'][0]['type_name'] === 'Нативный тип', 'Grid search and type labels');
$page = $process(['operation'=>'gridTypes', 'limit'=>0]);
$assert($page['success'] && $page['total'] === 2, 'Combo receives complete types list');
$_SERVER['REQUEST_METHOD'] = 'GET';
$assert(!$process(['operation'=>'saveType', 'code'=>'forbidden', 'name'=>'GET'])['success'], 'GET mutations rejected');
echo "Passed $checks MySQL type checks using temporary tables.\n";
