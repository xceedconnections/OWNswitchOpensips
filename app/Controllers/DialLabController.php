<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Dial\CallLimitService;
use Ownswitch\Services\Dial\LabDialogService;
use Ownswitch\Services\Reports\LiveDialStatsService;

/** Staff Dial Lab — dummy concurrent calls (no trunk) + CPS / CC verification. */
final class DialLabController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        $sipUsers = $this->sipUserOptions();
        $selected = trim((string) $r->query('sip', ''));
        if ($selected === '' && $sipUsers !== []) {
            $selected = (string) array_key_first($sipUsers);
        }
        $lab = new LabDialogService($this->db());
        $limits = new CallLimitService($this->db());
        $snap = $selected !== '' ? $limits->snapshotSipUser($selected) : null;
        $stats = (new LiveDialStatsService($this->db()))->snapshot($selected !== '' ? $selected : null);
        $labCalls = $lab->listNormalized($selected !== '' ? $selected : null);
        $burst = $_SESSION['dial_lab_burst'] ?? null;
        unset($_SESSION['dial_lab_burst']);

        return $this->view('dial/lab', [
            'title' => 'Dial Lab',
            'sipUsers' => $sipUsers,
            'selected' => $selected,
            'snap' => $snap,
            'stats' => $stats,
            'labCalls' => $labCalls,
            'burst' => is_array($burst) ? $burst : null,
            'count' => (int) ($r->query('count', 3) ?: 3),
            'stagger' => (int) ($r->query('stagger', 0) ?: 0),
            'cpsAttempts' => (int) ($r->query('cps_n', 20) ?: 20),
        ]);
    }

    public function start(Request $r, array $p = []): Response
    {
        $sip = strtolower(trim((string) $r->input('sip', '')));
        $count = (int) $r->input('count', 3);
        $stagger = (int) $r->input('stagger', 0);
        if ($sip === '' || !isset($this->sipUserOptions()[$sip])) {
            return $this->redirect($r, '/dial-lab', 'Pick a valid SIP user.', 'danger');
        }
        $uid = isset($this->user($r)['id']) ? (int) $this->user($r)['id'] : null;
        $created = (new LabDialogService($this->db()))->start($sip, $count, $uid, $stagger);
        $this->audit($r, 'dial_lab.start', 'lab_dialogs', null, null, [
            'sip' => $sip,
            'count' => count($created),
        ]);
        return $this->redirect(
            $r,
            '/dial-lab?sip=' . rawurlencode($sip),
            'Started ' . count($created) . ' dummy Connected call(s) for ' . $sip . ' (no trunk). Open Calls Online to watch MM:SS.',
            'success'
        );
    }

    public function stop(Request $r, array $p = []): Response
    {
        $sip = strtolower(trim((string) $r->input('sip', '')));
        $callid = trim((string) $r->input('callid', ''));
        $lab = new LabDialogService($this->db());
        if ($callid !== '') {
            $ok = $lab->endByCallid($callid);
            return $this->redirect(
                $r,
                '/dial-lab' . ($sip !== '' ? '?sip=' . rawurlencode($sip) : ''),
                $ok ? 'Ended lab call.' : 'Lab call not found.',
                $ok ? 'success' : 'warning'
            );
        }
        $n = $lab->endAll($sip !== '' ? $sip : null);
        $this->audit($r, 'dial_lab.stop', 'lab_dialogs', null, null, ['sip' => $sip, 'ended' => $n]);
        return $this->redirect(
            $r,
            '/dial-lab' . ($sip !== '' ? '?sip=' . rawurlencode($sip) : ''),
            'Ended ' . $n . ' lab call(s).',
            'success'
        );
    }

    public function cpsBurst(Request $r, array $p = []): Response
    {
        $sip = strtolower(trim((string) $r->input('sip', '')));
        $n = (int) $r->input('cps_n', 20);
        if ($sip === '' || !isset($this->sipUserOptions()[$sip])) {
            return $this->redirect($r, '/dial-lab', 'Pick a valid SIP user.', 'danger');
        }
        $burst = (new CallLimitService($this->db()))->burstSipUser($sip, $n);
        $_SESSION['dial_lab_burst'] = $burst;
        $msg = "CPS burst: {$burst['allowed']} allowed / {$burst['denied']} denied"
            . ($burst['first_deny'] ? " (first deny: {$burst['first_deny']})" : '');
        return $this->redirect($r, '/dial-lab?sip=' . rawurlencode($sip) . '&cps_n=' . $n, $msg, $burst['denied'] > 0 ? 'warning' : 'success');
    }

    public function checkLimit(Request $r, array $p = []): Response
    {
        $sip = strtolower(trim((string) $r->input('sip', '')));
        if ($sip === '' || !isset($this->sipUserOptions()[$sip])) {
            return $this->redirect($r, '/dial-lab', 'Pick a valid SIP user.', 'danger');
        }
        // Simulate one more channel attempt without CPS spam: snapshot + authorize once
        $svc = new CallLimitService($this->db());
        $before = $svc->snapshotSipUser($sip);
        $res = $svc->authorizeSipUser($sip);
        $after = $svc->snapshotSipUser($sip);
        $msg = ($res['allow'] ? 'ALLOW' : 'DENY') . ' ' . $res['reason']
            . " · channels {$after['using']}/"
            . ($after['unlimited_channels'] ? '∞' : (string) $after['max_channels'])
            . " · cps {$after['cps']}/"
            . ($after['unlimited_cps'] ? '∞' : (string) $after['max_cps'])
            . " (before using={$before['using']})";
        return $this->redirect($r, '/dial-lab?sip=' . rawurlencode($sip), $msg, $res['allow'] ? 'success' : 'warning');
    }

    /** @return array<string,string> username => label */
    private function sipUserOptions(): array
    {
        $out = [];
        try {
            $rows = $this->db()->query(
                "SELECT username, max_channels, max_cps FROM sip_accounts WHERE status = 'active' ORDER BY username LIMIT 500"
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $u = strtolower((string) ($row['username'] ?? ''));
                if ($u === '') {
                    continue;
                }
                $cc = (int) ($row['max_channels'] ?? -1);
                $cps = (int) ($row['max_cps'] ?? -1);
                $out[$u] = $u . ' (CC ' . ($cc < 0 ? '∞' : (string) $cc) . ', CPS ' . ($cps < 0 ? '∞' : (string) $cps) . ')';
            }
        } catch (\Throwable) {
        }
        return $out;
    }
}
