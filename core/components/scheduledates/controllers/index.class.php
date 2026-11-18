<?php
use MODX\Revolution\modExtraManagerController;

class ScheduleDatesIndexManagerController extends modExtraManagerController
{
    public function checkPermissions()
    {
        return $this->modx->hasPermission('settings');
    }

    public function getPageTitle()
    {
        return 'ScheduleDates — расписание';
    }

    public function getLanguageTopics()
    {
        return ['scheduledates:default'];
    }

    public function loadCustomCssJs()
    {
        $url = $this->modx->getOption('assets_url') . 'components/scheduledates/';
        $this->addHtml('<script>window.ScheduleDatesConfig=' . json_encode([
            'connector' => $url . 'connector.php',
            'auth' => $this->modx->user->getUserToken($this->modx->context->get('key')),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>');
        $this->addLastJavascript($url . 'js/mgr.js?v=1.0.0');
    }

    public function getTemplateFile()
    {
        return '';
    }
}
