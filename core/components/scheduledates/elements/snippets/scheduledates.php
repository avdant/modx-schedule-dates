<?php
/** @var \MODX\Revolution\modX $modx */
/** @var array $scriptProperties */
require_once MODX_CORE_PATH . 'components/scheduledates/src/Schedule.php';

$escape = static function ($value): string {
    // Escape MODX tags as well as HTML when rendering user-maintained content.
    return str_replace(['[', ']'], ['&#91;', '&#93;'], htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
};
try {
    $yearOption = $modx->getOption('year', $scriptProperties, '');
    $year = \ScheduleDates\Schedule::year($yearOption === '' ? date('Y') : $yearOption);
    $type = (string)$modx->getOption('type', $scriptProperties, '');
    $events = (new \ScheduleDates\Schedule($modx))->list($year, $type, true);
    $today = date('Y-m-d');
    if ((bool)$modx->getOption('upcoming', $scriptProperties, false)) {
        $events = array_values(array_filter($events, static function (array $event) use ($today): bool {
            return $event['event_date'] >= $today;
        }));
    }
    if ((bool)$modx->getOption('css', $scriptProperties, true)) {
        $modx->regClientCSS($modx->getOption('assets_url') . 'components/scheduledates/css/web.css');
    }
    $months = [1=>'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
    $weekdays = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];
    $types = array_column((new \ScheduleDates\Schedule($modx))->types(), 'name', 'code');
    $render = static function (string $property, string $default, array $placeholders) use ($modx, $scriptProperties): string {
        $name = trim((string)$modx->getOption($property, $scriptProperties, $default));
        return $modx->getChunk($name === '' ? $default : $name, $placeholders);
    };
    $groupByMonth = (bool)$modx->getOption('groupByMonth', $scriptProperties, true);
    $showEmptyMonths = $groupByMonth && (bool)$modx->getOption('showEmptyMonths', $scriptProperties, false);
    $common = ['year'=>$year, 'type'=>$escape($type), 'count'=>count($events)];
    if (!$events && !$showEmptyMonths) {
        $emptyText = $escape($modx->getOption('emptyText', $scriptProperties, 'Даты пока не назначены.'));
        // Custom wrappers decide how to represent an empty select/list, without injected <p> tags.
        $customWrapper = trim((string)$modx->getOption('tplWrapper', $scriptProperties, ''));
        $output = ($customWrapper === '' || $customWrapper === 'scheduledatesWrapper')
            ? '<p class="scheduledates-empty">' . $emptyText . '</p>' : '';
        return $render('tplWrapper', 'scheduledatesWrapper', $common + ['output'=>$output, 'emptyText'=>$emptyText]);
    }
    $groups = [];
    if ($showEmptyMonths) {
        foreach ($months as $number=>$name) {
            $month = str_pad((string)$number, 2, '0', STR_PAD_LEFT);
            $groups[$year . '-' . $month] = ['year'=>$year, 'month'=>$month, 'monthName'=>$name, 'count'=>0, 'output'=>''];
        }
    }
    $output = '';
    foreach ($events as $event) {
        $date = new \DateTimeImmutable($event['event_date']);
        $key = $date->format('Y-m');
        $description = $escape($event['description']);
        $isPast = $event['event_date'] < $today;
        $row = $render('tpl', 'scheduledatesRow', [
            'id'=>(int)$event['id'],
            'date'=>$escape($event['event_date']),
            'dateFormatted'=>$date->format('d.m.Y'),
            'day'=>$date->format('d'),
            'month'=>$date->format('m'),
            'year'=>(int)$date->format('Y'),
            'monthName'=>$months[(int)$date->format('n')],
            'weekday'=>$weekdays[(int)$date->format('w')],
            'isPast'=>$isPast ? 1 : 0,
            'dateClass'=>$isPast ? 'is-past' : 'is-upcoming',
            'title'=>$escape($event['title']),
            'type'=>$escape($event['type']),
            'typeName'=>$escape($types[$event['type']] ?? $event['type']),
            'description'=>$description,
            'descriptionHtml'=>$description !== '' ? '<p>' . nl2br($description) . '</p>' : '',
        ]);
        if ($groupByMonth) {
            if (!isset($groups[$key])) {
                $groups[$key] = ['year'=>$year, 'month'=>$date->format('m'), 'monthName'=>$months[(int)$date->format('n')], 'count'=>0, 'output'=>''];
            }
            $groups[$key]['output'] .= $row;
            $groups[$key]['count']++;
        } else {
            $output .= $row;
        }
    }
    foreach ($groups as $group) {
        $output .= $render('tplMonth', 'scheduledatesMonth', $group);
    }
    return $render('tplWrapper', 'scheduledatesWrapper', $common + ['output'=>$output, 'emptyText'=>'']);
} catch (\Throwable $error) {
    $modx->log(\MODX\Revolution\modX::LOG_LEVEL_ERROR, '[ScheduleDates] ' . $error->getMessage());
    return '';
}
