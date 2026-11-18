<?php
use xPDO\Transport\xPDOTransport;

if (!$object || !isset($object->xpdo)) {
    return false;
}
$modx = $object->xpdo;
switch ($options[xPDOTransport::PACKAGE_ACTION]) {
    case xPDOTransport::ACTION_INSTALL:
        try {
            require_once MODX_CORE_PATH . 'components/scheduledates/src/Schedule.php';
            (new \ScheduleDates\Schedule($modx))->install();
        } catch (\Throwable $error) {
            $modx->log(\MODX\Revolution\modX::LOG_LEVEL_ERROR, '[ScheduleDates install] ' . $error->getMessage());
            return false;
        }
        break;
    case xPDOTransport::ACTION_UNINSTALL:
        // Preserve the schedule on uninstall; removing data is an administrator's decision.
        break;
}
return true;
