<?php
/** Run: php _build/build.transport.php /absolute/path/to/modx/ */
use MODX\Revolution\modX;
use MODX\Revolution\modCategory;
use MODX\Revolution\modChunk;
use MODX\Revolution\modMenu;
use MODX\Revolution\modSnippet;
use MODX\Revolution\Transport\modPackageBuilder;
use xPDO\Transport\xPDOTransport;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
$installation = rtrim($argv[1] ?? (getenv('MODX_BASE_PATH') ?: ''), '/\\');
if (!$installation || !is_file($installation . '/config.core.php')) {
    fwrite(STDERR, "Usage: php _build/build.transport.php /path/to/modx/\n");
    exit(1);
}
require_once $installation . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';
$modx = new modX();
$modx->initialize('mgr');
$root = dirname(__DIR__);
$builder = new modPackageBuilder($modx);
$builder->createPackage('scheduledates', '1.0.0', 'pl');
$builder->registerNamespace('scheduledates', false, true, '{core_path}components/scheduledates/', '{assets_path}components/scheduledates/');

$category = $modx->newObject(modCategory::class);
$category->set('category', 'ScheduleDates');
$snippet = $modx->newObject(modSnippet::class);
$snippet->fromArray([
    'name'=>'ScheduleDates',
    'description'=>'Расписание событий. Вызывайте некешированным: [[!ScheduleDates]]',
    'snippet'=>preg_replace('/^<\?php\s*/', '', file_get_contents($root . '/core/components/scheduledates/elements/snippets/scheduledates.php')),
]);
$snippet->setProperties([
    ['name'=>'tpl', 'desc'=>'Чанк события', 'type'=>'textfield', 'value'=>'scheduledatesRow'],
    ['name'=>'tplWrapper', 'desc'=>'Чанк обёртки', 'type'=>'textfield', 'value'=>'scheduledatesWrapper'],
    ['name'=>'tplMonth', 'desc'=>'Чанк группы месяца', 'type'=>'textfield', 'value'=>'scheduledatesMonth'],
    ['name'=>'groupByMonth', 'desc'=>'Группировать по месяцам', 'type'=>'combo-boolean', 'value'=>true],
    ['name'=>'showEmptyMonths', 'desc'=>'Выводить все 12 месяцев при группировке, включая месяцы без событий', 'type'=>'combo-boolean', 'value'=>false],
    ['name'=>'year', 'desc'=>'Год (пустое значение — текущий)', 'type'=>'textfield', 'value'=>''],
    ['name'=>'type', 'desc'=>'Код типа из справочника или пустое значение для всех типов', 'type'=>'textfield', 'value'=>''],
    ['name'=>'upcoming', 'desc'=>'Только сегодняшние и будущие даты', 'type'=>'combo-boolean', 'value'=>false],
    ['name'=>'css', 'desc'=>'Подключать стандартные стили', 'type'=>'combo-boolean', 'value'=>true],
    ['name'=>'emptyText', 'desc'=>'Сообщение для пустого расписания', 'type'=>'textfield', 'value'=>'Даты пока не назначены.'],
]);
$snippets = [$snippet];
$category->addMany($snippets, 'Snippets');
$chunks = [];
foreach (glob($root . '/core/components/scheduledates/elements/chunks/*.tpl') as $file) {
    $chunk = $modx->newObject(modChunk::class);
    $chunk->fromArray(['name'=>basename($file, '.tpl'), 'snippet'=>file_get_contents($file)]);
    $chunks[] = $chunk;
}
$category->addMany($chunks, 'Chunks');
$vehicle = $builder->createVehicle($category, [
    xPDOTransport::UNIQUE_KEY=>'category',
    xPDOTransport::PRESERVE_KEYS=>false,
    xPDOTransport::UPDATE_OBJECT=>true,
    xPDOTransport::RELATED_OBJECTS=>true,
    xPDOTransport::RELATED_OBJECT_ATTRIBUTES=>[
        'Chunks'=>[
            xPDOTransport::UNIQUE_KEY=>'name',
            xPDOTransport::PRESERVE_KEYS=>false,
            xPDOTransport::UPDATE_OBJECT=>false,
        ],
        'Snippets'=>[
            xPDOTransport::UNIQUE_KEY=>'name',
            xPDOTransport::PRESERVE_KEYS=>false,
            xPDOTransport::UPDATE_OBJECT=>true,
        ],
    ],
]);
$vehicle->resolve('file', [
    'source'=>$root . '/core/components/scheduledates',
    'target'=>"return MODX_CORE_PATH . 'components/';",
]);
$vehicle->resolve('file', [
    'source'=>$root . '/assets/components/scheduledates',
    'target'=>"return MODX_ASSETS_PATH . 'components/';",
]);
$vehicle->resolve('php', ['source'=>__DIR__ . '/resolvers/schema.php']);
$vehicle->resolve('php', ['source'=>__DIR__ . '/resolvers/package-name.php']);
$builder->putVehicle($vehicle);

$menu = $modx->newObject(modMenu::class);
$menu->fromArray([
    'text'=>'scheduledates', 'parent'=>'components', 'action'=>'index',
    'namespace'=>'scheduledates', 'description'=>'scheduledates.menu_desc',
    'permissions'=>'settings', 'menuindex'=>0,
], '', true);
$builder->putVehicle($builder->createVehicle($menu, [
    xPDOTransport::PRESERVE_KEYS=>true,
    xPDOTransport::UPDATE_OBJECT=>true,
    xPDOTransport::UNIQUE_KEY=>'text',
]));
$builder->setPackageAttributes([
    'license'=>file_get_contents($root . '/LICENSE'),
    'readme'=>file_get_contents($root . '/README.md'),
    'changelog'=>file_get_contents($root . '/CHANGELOG.md'),
    'requires'=>['modx'=>'>=3.0.0', 'php'=>'>=7.4'],
]);
if (!$builder->pack()) {
    fwrite(STDERR, "Package build failed.\n");
    exit(1);
}
$source = MODX_CORE_PATH . 'packages/scheduledates-1.0.0-pl.transport.zip';
$dist = $root . '/dist';
if (!is_dir($dist) && !mkdir($dist, 0775, true)) {
    throw new RuntimeException('Cannot create dist directory.');
}
if (!copy($source, $dist . '/' . basename($source))) {
    throw new RuntimeException('Cannot copy package to dist.');
}
echo "Package: " . $dist . '/' . basename($source) . "\n";
