<?php
namespace ScheduleDates;

use DateTimeImmutable;
use InvalidArgumentException;
use MODX\Revolution\modX;
use PDO;

final class Schedule
{
    private modX $modx;
    private string $table;
    private string $typesTable;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
        $this->table = '`' . str_replace('`', '', $modx->getOption('table_prefix')) . 'scheduledates_events`';
        $this->typesTable = '`' . str_replace('`', '', $modx->getOption('table_prefix')) . 'scheduledates_types`';
    }

    public function install(): void
    {
        $sql = "CREATE TABLE {$this->table} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_date DATE NOT NULL,
            title VARCHAR(190) NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'exam',
            description TEXT NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY event_identity (event_date, title, type),
            KEY schedule_lookup (active, event_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if ($this->modx->exec($sql) === false) {
            throw new \RuntimeException('Cannot create ScheduleDates table.');
        }
        if ($this->modx->exec("CREATE TABLE {$this->typesTable} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(20) NOT NULL,
            name VARCHAR(190) NOT NULL,
            UNIQUE KEY type_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci") === false) {
            throw new \RuntimeException('Cannot create event types table.');
        }
        $this->execute("INSERT INTO {$this->typesTable} (code,name) VALUES ('exam','Экзамен')");
    }

    private function execute(string $sql, array $params = [])
    {
        $query = $this->modx->prepare($sql);
        if (!$query || !$query->execute($params)) throw new \RuntimeException('Schedule database operation failed.');
        return $query;
    }

    public static function typeCode(string $code): string
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,19}$/D', $code)) {
            throw new InvalidArgumentException('Код типа: 1–20 символов, латинские строчные буквы, цифры, дефис или подчёркивание; первый символ — буква.');
        }
        return $code;
    }

    public function types(): array
    {
        return $this->execute("SELECT id,code,name FROM {$this->typesTable} ORDER BY name,id")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveType(array $input): void
    {
        $id = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT);
        $code = self::typeCode(trim((string)($input['code'] ?? '')));
        $name = trim((string)($input['name'] ?? ''));
        if ($id === false || $id < 0 || $name === '' || mb_strlen($name) > 190) {
            throw new InvalidArgumentException('Укажите название типа (до 190 символов).');
        }
        if (!$this->modx->beginTransaction()) throw new \RuntimeException('Cannot start type transaction.');
        try {
            $old = $id ? $this->execute("SELECT code FROM {$this->typesTable} WHERE id=:id FOR UPDATE", [':id'=>$id])->fetchColumn() : false;
            if ($id && $old === false) throw new InvalidArgumentException('Тип события не найден.');
            $duplicate = $this->execute("SELECT id FROM {$this->typesTable} WHERE code=:code AND id<>:id", [':code'=>$code, ':id'=>$id])->fetchColumn();
            if ($duplicate !== false) throw new InvalidArgumentException('Тип с таким кодом уже существует.');
            if ($id) {
                $this->execute("UPDATE {$this->typesTable} SET code=:code,name=:name WHERE id=:id", [':code'=>$code, ':name'=>$name, ':id'=>$id]);
                if ($old !== $code) $this->execute("UPDATE {$this->table} SET type=:new WHERE type=:old", [':new'=>$code, ':old'=>$old]);
            } else {
                $this->execute("INSERT INTO {$this->typesTable} (code,name) VALUES (:code,:name)", [':code'=>$code, ':name'=>$name]);
            }
            if (!$this->modx->commit()) throw new \RuntimeException('Cannot commit type transaction.');
        } catch (\Throwable $error) {
            $this->modx->rollBack();
            throw $error;
        }
    }

    public function removeType($id): void
    {
        $id = filter_var($id, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) throw new InvalidArgumentException('Некорректный идентификатор типа.');
        if (!$this->modx->beginTransaction()) throw new \RuntimeException('Cannot start type transaction.');
        try {
            $code = $this->execute("SELECT code FROM {$this->typesTable} WHERE id=:id FOR UPDATE", [':id'=>$id])->fetchColumn();
            if ($code === false) throw new InvalidArgumentException('Тип события не найден.');
            if ($this->execute("SELECT COUNT(*) FROM {$this->table} WHERE type=:type", [':type'=>$code])->fetchColumn()) {
                throw new InvalidArgumentException('Тип используется в событиях. Сначала измените тип этих событий или удалите их.');
            }
            $this->execute("DELETE FROM {$this->typesTable} WHERE id=:id", [':id'=>$id]);
            if (!$this->modx->commit()) throw new \RuntimeException('Cannot commit type transaction.');
        } catch (\Throwable $error) {
            $this->modx->rollBack();
            throw $error;
        }
    }

    public static function year($value): int
    {
        $year = filter_var($value, FILTER_VALIDATE_INT);
        if ($year === false || $year < 1900 || $year > 2100) {
            throw new InvalidArgumentException('Укажите год от 1900 до 2100.');
        }
        return $year;
    }

    public static function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Некорректная дата: ' . $value);
        }
        self::year($date->format('Y'));
        return $value;
    }

    public function list(int $year, string $type = '', bool $publishedOnly = false): array
    {
        self::year($year);
        $where = 'event_date >= :start AND event_date < :end';
        $params = [':start' => "$year-01-01", ':end' => ($year + 1) . '-01-01'];
        if ($type !== '') {
            self::typeCode($type);
            $where .= ' AND type = :type';
            $params[':type'] = $type;
        }
        if ($publishedOnly) {
            $where .= ' AND active = 1';
        }
        $query = $this->modx->prepare("SELECT * FROM {$this->table} WHERE $where ORDER BY event_date, title, id");
        if (!$query || !$query->execute($params)) {
            throw new \RuntimeException('Cannot load schedule.');
        }
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function save(array $input): int
    {
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 190) {
            throw new InvalidArgumentException('Название обязательно, максимум 190 символов.');
        }
        $type = (string)($input['type'] ?? 'exam');
        self::typeCode($type);
        $dates = $input['dates'] ?? [];
        if (!is_array($dates) || !$dates || count($dates) > 366) {
            throw new InvalidArgumentException('Выберите от 1 до 366 дат.');
        }
        $dates = array_values(array_unique(array_map([self::class, 'date'], $dates)));
        $id = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT);
        if ($id === false || $id < 0 || ($id > 0 && count($dates) !== 1)) {
            throw new InvalidArgumentException('Некорректный идентификатор события.');
        }
        $description = trim((string)($input['description'] ?? ''));
        if (mb_strlen($description) > 10000) {
            throw new InvalidArgumentException('Описание: максимум 10000 символов.');
        }
        $active = empty($input['active']) ? 0 : 1;
        $sql = $id > 0
            ? "UPDATE {$this->table} SET event_date=:date, title=:title, type=:type, description=:description, active=:active WHERE id=:id"
            : "INSERT INTO {$this->table} (event_date,title,type,description,active) VALUES (:date,:title,:type,:description,:active) ON DUPLICATE KEY UPDATE description=VALUES(description), active=VALUES(active)";
        if (!$this->modx->beginTransaction()) {
            throw new \RuntimeException('Cannot start schedule transaction.');
        }
        try {
            if ($this->execute("SELECT code FROM {$this->typesTable} WHERE code=:type FOR UPDATE", [':type'=>$type])->fetchColumn() === false) {
                throw new InvalidArgumentException('Неизвестный тип события.');
            }
            if ($id > 0 && $this->execute("SELECT id FROM {$this->table} WHERE id=:id FOR UPDATE", [':id'=>$id])->fetchColumn() === false) {
                throw new InvalidArgumentException('Событие не найдено. Возможно, оно уже удалено.');
            }
            $query = $this->modx->prepare($sql);
            foreach ($dates as $date) {
                $params = [':date'=>$date, ':title'=>$title, ':type'=>$type, ':description'=>$description, ':active'=>$active];
                if ($id > 0) {
                    $params[':id'] = $id;
                }
                try {
                    $saved = $query && $query->execute($params);
                } catch (\PDOException $error) {
                    throw $this->eventSaveError($error->errorInfo ?? [$error->getCode(), 0, $error->getMessage()]);
                }
                if (!$saved) {
                    throw $this->eventSaveError($query ? $query->errorInfo() : $this->modx->errorInfo());
                }
            }
            if (!$this->modx->commit()) {
                throw new \RuntimeException('Cannot commit schedule transaction.');
            }
        } catch (\Throwable $error) {
            $this->modx->rollBack();
            throw $error;
        }
        return count($dates);
    }

    private function eventSaveError(array $info): \Throwable
    {
        if ((int)($info[1] ?? 0) === 1062) {
            return new InvalidArgumentException('На выбранную дату уже есть событие с таким названием и типом. Измените дату, название или тип события.');
        }
        return new \RuntimeException('Cannot save event: ' . implode(' | ', $info));
    }

    public function remove($id): void
    {
        $id = filter_var($id, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            throw new InvalidArgumentException('Некорректный идентификатор события.');
        }
        $query = $this->modx->prepare("DELETE FROM {$this->table} WHERE id=:id");
        if (!$query || !$query->execute([':id'=>$id])) {
            throw new \RuntimeException('Cannot delete event.');
        }
    }
}
