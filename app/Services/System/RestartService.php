<?php

declare(strict_types=1);

namespace Ownswitch\Services\System;

final class RestartService
{
    private const BIN = '/usr/local/sbin/ownswitch-restart';

    /** @return array{ok:bool,detail:string,helper:bool,opensips_active:bool,opensips_state:string,host_uptime:string} */
    public function status(): array
    {
        $helper = is_file(self::BIN);
        if (!$helper) {
            return [
                'ok' => false,
                'detail' => 'Restart helper is not installed yet.',
                'helper' => false,
                'opensips_active' => false,
                'opensips_state' => 'unknown',
                'host_uptime' => '',
            ];
        }
        $res = SudoHelper::run(self::BIN, ['status']);
        $f = $res['fields'];
        return [
            'ok' => $res['ok'],
            'detail' => $res['detail'],
            'helper' => true,
            'opensips_active' => ($f['opensips_active'] ?? '') === '1',
            'opensips_state' => (string) ($f['opensips_state'] ?? 'unknown'),
            'host_uptime' => (string) ($f['host_uptime'] ?? ''),
        ];
    }

    /** @return array{ok:bool,detail:string} */
    public function restartOpensips(): array
    {
        if (!is_file(self::BIN)) {
            return [
                'ok' => false,
                'detail' => 'Restart helper is not installed. As root run: sudo bash /opt/ownswitch-src/deploy/install-restart-helper.sh',
            ];
        }
        $res = SudoHelper::run(self::BIN, ['opensips']);
        return ['ok' => $res['ok'], 'detail' => $res['detail']];
    }

    /** @return array{ok:bool,detail:string} */
    public function restartServer(): array
    {
        if (!is_file(self::BIN)) {
            return [
                'ok' => false,
                'detail' => 'Restart helper is not installed. As root run: sudo bash /opt/ownswitch-src/deploy/install-restart-helper.sh',
            ];
        }
        $res = SudoHelper::run(self::BIN, ['server']);
        return ['ok' => $res['ok'], 'detail' => $res['detail']];
    }
}
