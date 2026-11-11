<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\StirShaken\StirShakenCertificateService;

/** System Settings → STIR/SHAKEN certificates (ConnexCS-style inventory). */
final class StirShakenController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        $svc = new StirShakenCertificateService($this->db());
        try {
            $certs = $svc->list();
        } catch (\Throwable $e) {
            error_log('[ownswitch] stir index: ' . $e->getMessage());
            return $this->view('error', [
                'title' => 'STIR/SHAKEN',
                'message' => 'STIR/SHAKEN tables are missing. On the server run: '
                    . 'sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env php /opt/ownswitch/current/bin/migrate.php',
                'code' => 500,
            ], 500);
        }
        $rows = [];
        foreach ($certs as $c) {
            $c['resolved_x5u'] = $svc->resolveX5u($c);
            $rows[] = $c;
        }
        return $this->view('stir_shaken/index', [
            'title' => 'STIR/SHAKEN Cert.',
            'certs' => $rows,
            'canWrite' => $this->canWrite($r),
        ]);
    }

    public function create(Request $r, array $p = []): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        return $this->view('stir_shaken/form', [
            'title' => 'New STIR/SHAKEN certificate',
            'cert' => null,
            'errors' => [],
            'data' => [
                'name' => '',
                'x5u_url' => '',
                'origid' => '',
                'status' => 'active',
                'notes' => '',
                'cert_pem' => '',
                'private_key_pem' => '',
            ],
            'resolvedX5u' => null,
        ]);
    }

    public function store(Request $r, array $p = []): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $svc = new StirShakenCertificateService($this->db());
        $data = $this->formData($r);
        $result = $svc->create(
            $data['name'],
            $data['cert_pem'],
            $data['private_key_pem'],
            $data['x5u_url'],
            $data['origid'],
            $data['status'],
            $data['notes']
        );
        if (!$result['ok']) {
            return $this->view('stir_shaken/form', [
                'title' => 'New STIR/SHAKEN certificate',
                'cert' => null,
                'errors' => ['_form' => $result['error']],
                'data' => $data,
                'resolvedX5u' => null,
            ], 422);
        }
        $this->audit($r, 'create', 'stir_certificates', $result['id'], null, [
            'name' => $data['name'],
            'status' => $data['status'],
        ]);
        return $this->redirect($r, '/settings/stir-shaken/' . $result['id'] . '/edit', 'Certificate saved.');
    }

    public function edit(Request $r, array $p): Response
    {
        $svc = new StirShakenCertificateService($this->db());
        $cert = $svc->find((int) $p['id']);
        if ($cert === null) {
            return $this->notFound();
        }
        return $this->view('stir_shaken/form', [
            'title' => 'Edit STIR/SHAKEN certificate',
            'cert' => $cert,
            'errors' => [],
            'data' => [
                'name' => (string) $cert['name'],
                'x5u_url' => (string) ($cert['x5u_url'] ?? ''),
                'origid' => (string) $cert['origid'],
                'status' => (string) $cert['status'],
                'notes' => (string) ($cert['notes'] ?? ''),
                'cert_pem' => '',
                'private_key_pem' => '',
            ],
            'resolvedX5u' => $svc->resolveX5u($cert),
            'canWrite' => $this->canWrite($r),
        ]);
    }

    public function update(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $id = (int) $p['id'];
        $svc = new StirShakenCertificateService($this->db());
        $cert = $svc->find($id);
        if ($cert === null) {
            return $this->notFound();
        }
        $data = $this->formData($r);
        $result = $svc->update(
            $id,
            $data['name'],
            $data['cert_pem'] !== '' ? $data['cert_pem'] : null,
            $data['private_key_pem'] !== '' ? $data['private_key_pem'] : null,
            $data['x5u_url'],
            $data['origid'],
            $data['status'],
            $data['notes']
        );
        if (!$result['ok']) {
            return $this->view('stir_shaken/form', [
                'title' => 'Edit STIR/SHAKEN certificate',
                'cert' => $cert,
                'errors' => ['_form' => $result['error']],
                'data' => $data,
                'resolvedX5u' => $svc->resolveX5u($cert),
                'canWrite' => true,
            ], 422);
        }
        $this->audit($r, 'update', 'stir_certificates', $id, [
            'name' => $cert['name'],
            'status' => $cert['status'],
        ], [
            'name' => $data['name'],
            'status' => $data['status'],
        ]);
        return $this->redirect($r, '/settings/stir-shaken/' . $id . '/edit', 'Certificate saved.');
    }

    public function destroy(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $id = (int) $p['id'];
        $svc = new StirShakenCertificateService($this->db());
        $cert = $svc->find($id);
        if ($cert === null) {
            return $this->notFound();
        }
        $svc->delete($id);
        $this->audit($r, 'delete', 'stir_certificates', $id, ['name' => $cert['name']], null);
        return $this->redirect($r, '/settings/stir-shaken', 'Certificate deleted.');
    }

    /** Public x5u endpoint — serves the certificate PEM for PASSporT verification. */
    public function publicPem(Request $r, array $p): Response
    {
        $token = (string) ($p['token'] ?? '');
        $token = preg_replace('/\.pem$/i', '', $token) ?? $token;
        $svc = new StirShakenCertificateService($this->db());
        try {
            $cert = $svc->findByPublicToken($token);
        } catch (\Throwable) {
            return Response::text('Not found', 404);
        }
        if ($cert === null) {
            return Response::text('Not found', 404);
        }
        $pem = trim((string) $cert['cert_pem']) . "\n";
        return Response::text($pem, 200)
            ->withHeader('Content-Type', 'application/x-pem-file')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    /** @return array{name:string,x5u_url:string,origid:string,status:string,notes:string,cert_pem:string,private_key_pem:string} */
    private function formData(Request $r): array
    {
        return [
            'name' => trim((string) $r->input('name', '')),
            'x5u_url' => trim((string) $r->input('x5u_url', '')),
            'origid' => trim((string) $r->input('origid', '')),
            'status' => (string) $r->input('status', 'active') === 'disabled' ? 'disabled' : 'active',
            'notes' => trim((string) $r->input('notes', '')),
            'cert_pem' => trim((string) $r->input('cert_pem', '')),
            'private_key_pem' => trim((string) $r->input('private_key_pem', '')),
        ];
    }

    private function canWrite(Request $r): bool
    {
        return in_array($this->user($r)['role'] ?? '', ['super_admin', 'admin'], true);
    }
}
