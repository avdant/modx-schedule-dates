<?php
/** Read-only diagnostics for schedule storage. CLI only. */
if (PHP_SAPI !== 'cli') exit('CLI only');
$installation = rtrim($argv[1] ?? '', '/\\');
require $installation . '/config.core.php';
require MODX_CORE_PATH . 'vendor/autoload.php';
$modx = new \MODX\Revolution\modX();
$modx->initialize('web');
$prefix = str_replace('`', '', $modx->getOption('table_prefix'));
$queries = [
    'database' => 'SELECT VERSION() AS version, @@sql_mode AS sql_mode',
    'events_schema' => 'SHOW CREATE TABLE `' . $prefix . 'scheduledates_events`',
    'events' => 'SELECT id,event_date,title,type,active FROM `' . $prefix . 'scheduledates_events` ORDER BY id LIMIT 30',
    'types' => 'SELECT id,code,name FROM `' . $prefix . 'scheduledates_types` ORDER BY id',
];
foreach ($queries as $label=>$sql) {
    $query = $modx->query($sql);
    echo $label . ': ' . json_encode($query ? $query->fetchAll(PDO::FETCH_ASSOC) : $modx->errorInfo(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}
