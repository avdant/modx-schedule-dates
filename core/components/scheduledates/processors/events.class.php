<?php
use MODX\Revolution\Processors\Processor;

class ScheduleDatesEventsProcessor extends Processor
{
    public function checkPermissions()
    {
        return $this->modx->user->hasSessionContext('mgr') && $this->modx->hasPermission('settings');
    }

    public function process()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return $this->failure('Требуется POST-запрос.');
        }
        if (!class_exists(\ScheduleDates\Schedule::class, false)) {
            require_once MODX_CORE_PATH . 'components/scheduledates/src/Schedule.php';
        }
        try {
            $service = new \ScheduleDates\Schedule($this->modx);
            switch ($this->getProperty('operation', 'list')) {
                case 'gridEvents':
                    $rows = $service->list(\ScheduleDates\Schedule::year($this->getProperty('year', date('Y'))));
                    $types = array_column($service->types(), 'name', 'code');
                    foreach ($rows as &$row) {
                        $row['type_name'] = $types[$row['type']] ?? $row['type'];
                    }
                    unset($row);
                    return $this->gridOutput($rows, ['id', 'event_date', 'title', 'type_name', 'active'], 'event_date');
                case 'gridTypes':
                    return $this->gridOutput($service->types(), ['id', 'name', 'code'], 'name');
                case 'list':
                    $rows = $service->list(\ScheduleDates\Schedule::year($this->getProperty('year')));
                    return $this->success('', ['events'=>$rows, 'types'=>$service->types()]);
                case 'saveType':
                    $data = $this->getProperty('data') !== null
                        ? json_decode($this->getProperty('data'), true)
                        : ['id'=>$this->getProperty('id', 0), 'code'=>$this->getProperty('code', ''), 'name'=>$this->getProperty('name', '')];
                    if (!is_array($data)) return $this->failure('Некорректные данные типа.');
                    $service->saveType($data);
                    return $this->success('Тип сохранён.');
                case 'removeType':
                    $service->removeType($this->getProperty('id'));
                    return $this->success('Тип удалён.');
                case 'save':
                    $data = $this->getProperty('data') !== null
                        ? json_decode($this->getProperty('data'), true)
                        : [
                            'id'=>$this->getProperty('id', 0),
                            'dates'=>[$this->getProperty('date_iso', '')],
                            'title'=>$this->getProperty('title', ''),
                            'description'=>$this->getProperty('description', ''),
                            'type'=>$this->getProperty('type', ''),
                            'active'=>$this->getProperty('active', 0),
                        ];
                    if (!is_array($data)) {
                        return $this->failure('Некорректные данные.');
                    }
                    return $this->success('Сохранено.', ['count'=>$service->save($data)]);
                case 'remove':
                    $service->remove($this->getProperty('id'));
                    return $this->success('Удалено.');
                default:
                    return $this->failure('Неизвестная операция.');
            }
        } catch (\InvalidArgumentException $error) {
            return $this->failure($error->getMessage());
        } catch (\Throwable $error) {
            $this->modx->log(\MODX\Revolution\modX::LOG_LEVEL_ERROR, '[ScheduleDates] ' . $error->getMessage());
            return $this->failure('Операция не выполнена. Подробности в журнале MODX.');
        }
    }

    private function gridOutput(array $rows, array $allowedSort, string $defaultSort): string
    {
        $query = mb_substr(trim((string)$this->getProperty('query', '')), 0, 200);
        if ($query !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($query): bool {
                foreach (['title', 'description', 'type_name', 'event_date', 'name', 'code'] as $field) {
                    if (isset($row[$field]) && mb_stripos($row[$field], $query) !== false) return true;
                }
                return false;
            }));
        }
        $sort = (string)$this->getProperty('sort', $defaultSort);
        if (!in_array($sort, $allowedSort, true)) $sort = $defaultSort;
        $direction = strtoupper((string)$this->getProperty('dir', 'ASC')) === 'DESC' ? -1 : 1;
        usort($rows, static function (array $a, array $b) use ($sort, $direction): int {
            $comparison = in_array($sort, ['id', 'active'], true)
                ? ((int)$a[$sort] <=> (int)$b[$sort])
                : strcmp(mb_strtolower((string)$a[$sort]), mb_strtolower((string)$b[$sort]));
            return ($comparison ?: ((int)$a['id'] <=> (int)$b['id'])) * $direction;
        });
        $total = count($rows);
        $start = max(0, (int)$this->getProperty('start', 0));
        $limit = max(0, min(200, (int)$this->getProperty('limit', 20)));
        return $this->outputArray($limit ? array_slice($rows, $start, $limit) : array_slice($rows, $start), $total);
    }
}
return ScheduleDatesEventsProcessor::class;
