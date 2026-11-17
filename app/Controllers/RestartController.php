<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\System\RestartService;

final class RestartController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        // Access gated by Admin Access → Restart via FeatureVisibilityMiddleware.
        return $this->view('settings/restart', [
            'title' => 'Restart',
            'status' => (new RestartService())->status(),
        ]);
    }

    public function opensips(Request $r, array $p = []): Response
    {
        $res = (new RestartService())->restartOpensips();
        try {
            $this->audit($r, 'restart_opensips', 'system', null, null, ['ok' => $res['ok']]);
        } catch (\Throwable) {
        }
        return $this->redirect($r, '/settings/restart', $res['detail'], $res['ok'] ? 'success' : 'danger');
    }

    public function server(Request $r, array $p = []): Response
    {
        $res = (new RestartService())->restartServer();
        try {
            $this->audit($r, 'restart_server', 'system', null, null, ['ok' => $res['ok']]);
        } catch (\Throwable) {
        }
        return $this->redirect($r, '/settings/restart', $res['detail'], $res['ok'] ? 'warning' : 'danger');
    }
}
