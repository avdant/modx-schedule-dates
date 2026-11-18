<?php
// Optional integration check: php tests/modx-chunks.php /path/to/modx/
use MODX\Revolution\modChunk;
use MODX\Revolution\modX;
use MODX\Revolution\modMenu;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
$installation = rtrim($argv[1] ?? '', '/\\');
require $installation . '/config.core.php';
require MODX_CORE_PATH . 'vendor/autoload.php';
$modx = new modX();
$modx->initialize('web');
$menu = $modx->newObject(modMenu::class);
$menu->fromArray(['text'=>'scheduledates', 'namespace'=>'scheduledates', 'action'=>'index'], '', true);
if ($menu->get('text') !== 'scheduledates') {
    throw new RuntimeException('Menu primary key must be populated for transport packaging.');
}
$render = static function (string $name, array $properties) use ($modx): string {
    $chunk = $modx->newObject(modChunk::class);
    $chunk->setContent(file_get_contents(dirname(__DIR__) . '/core/components/scheduledates/elements/chunks/' . $name . '.tpl'));
    $chunk->setCacheable(false);
    return $chunk->process($properties);
};
$row = [
    'id'=>1, 'date'=>'2027-02-01', 'dateFormatted'=>'01.02.2027',
    'day'=>'01', 'month'=>'02', 'year'=>2027, 'monthName'=>'Февраль',
    'weekday'=>'понедельник', 'type'=>'exam', 'typeName'=>'Экзамен',
    'isPast'=>0, 'dateClass'=>'is-upcoming',
    'title'=>'Экзамен &quot;А&quot; &#91;&#91;danger&#93;&#93;',
    'description'=>'', 'descriptionHtml'=>'',
];
$option = $render('scheduledatesOption', $row);
$select = $render('scheduledatesSelect', ['output'=>$option]);
if (strpos($select, 'value="2027-02-01"') === false || strpos($select, '&#91;&#91;danger&#93;&#93;') === false || strpos($select, '[[+') !== false) {
    throw new RuntimeException('MODX option/select rendering failed.');
}
$item = $render('scheduledatesRow', $row);
$month = $render('scheduledatesMonth', ['output'=>$item, 'monthName'=>'Февраль', 'year'=>2027]);
$calendar = $render('scheduledatesWrapper', ['output'=>$month]);
if (strpos($calendar, 'Февраль 2027') === false || strpos($calendar, 'понедельник') === false || strpos($calendar, '[[+') !== false) {
    throw new RuntimeException('MODX calendar chunk rendering failed.');
}
echo "MODX chunk integration checks passed (calendar and select).\n";
