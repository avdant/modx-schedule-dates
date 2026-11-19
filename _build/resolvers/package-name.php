<?php
use MODX\Revolution\Transport\modTransportPackage;
use xPDO\Transport\xPDOTransport;

if (!$object || !isset($object->xpdo)) return false;
if (!in_array($options[xPDOTransport::PACKAGE_ACTION] ?? null, [xPDOTransport::ACTION_INSTALL, xPDOTransport::ACTION_UPGRADE], true)) return true;

// The package manager displays package_name, independently of the lowercase signature.
$modx = $object->xpdo;
foreach ($modx->getCollection(modTransportPackage::class, ['signature'=>'scheduledates-1.1.0-pl']) as $package) {
    $package->set('package_name', 'ScheduleDates');
    if (!$package->save()) {
        $modx->log(\MODX\Revolution\modX::LOG_LEVEL_ERROR, '[ScheduleDates] Cannot update package display name.');
        return false;
    }
}
return true;
