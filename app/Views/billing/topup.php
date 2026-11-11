<?php
/** @var array $customers @var array $providers @var array $values @var list<string> $errors @var bool $refillEmailsEnabled */
$refillEmailsEnabled = !empty($refillEmailsEnabled);
$sendEmail = (($values['send_email'] ?? '0') === '1');
$target = (($values['target'] ?? 'customer') === 'provider') ? 'provider' : 'customer';
$providers = is_array($providers ?? null) ? $providers : [];
$customers = is_array($customers ?? null) ? $customers : [];
$isProvider = $target === 'provider';
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Refills</h1>
        <p class="os-page-sub">Credit a user or a provider account. Provider balance is bookkeeping only — trunks keep working even when empty.</p>
    </div>
</div>
<?php foreach ($errors as $err): ?><div class="alert alert-danger py-2"><?= e($err) ?></div><?php endforeach; ?>
<?php if ($customers === [] && $providers === []): ?>
    <div class="os-panel"><div class="os-empty"><div class="os-empty-title">Nothing to refill</div><div>Create a user or provider first.</div></div></div>
<?php else: ?>
<form method="post" action="<?= e(url('/billing/refill')) ?>" class="os-panel col-12 p-3 p-md-4" id="refill-form">
    <?= csrf_field() ?>
    <input type="hidden" name="nonce" value="<?= e(bin2hex(random_bytes(8))) ?>">
    <div class="mb-3">
        <label class="form-label fw-semibold" for="target">Account type</label>
        <select class="form-select" id="target" name="target">
            <option value="customer"<?= !$isProvider ? ' selected' : '' ?>>Refill user / customer</option>
            <option value="provider"<?= $isProvider ? ' selected' : '' ?>>Refill provider</option>
        </select>
    </div>

    <div class="mb-3<?= $isProvider ? ' d-none' : '' ?>" id="refill-pane-customer" data-refill-pane="customer">
        <label class="form-label fw-semibold" for="customer_id">User / customer</label>
        <select class="form-select" id="customer_id" name="customer_id"<?= $isProvider ? ' disabled' : ' required' ?>>
            <option value="">— select user —</option>
            <?php foreach ($customers as $id => $n): ?>
                <option value="<?= e((string) $id) ?>"<?= (string) ($values['customer_id'] ?? '') === (string) $id ? ' selected' : '' ?>><?= e($n) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($customers === []): ?>
            <div class="form-text text-danger">No users available. Create one under Clients → Users.</div>
        <?php endif; ?>
    </div>

    <div class="mb-3<?= $isProvider ? '' : ' d-none' ?>" id="refill-pane-provider" data-refill-pane="provider">
        <label class="form-label fw-semibold" for="carrier_id">Provider</label>
        <select class="form-select" id="carrier_id" name="carrier_id"<?= $isProvider ? ' required' : ' disabled' ?>>
            <option value="">— select provider —</option>
            <?php foreach ($providers as $id => $n): ?>
                <option value="<?= e((string) $id) ?>"<?= (string) ($values['carrier_id'] ?? '') === (string) $id ? ' selected' : '' ?>><?= e($n) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="form-text">Buy-side call charges deduct from this balance. Empty balance does not stop trunks.</div>
        <?php if ($providers === []): ?>
            <div class="form-text text-danger">No providers available. Create one under Routes → Providers.</div>
        <?php endif; ?>
    </div>

    <div class="mb-3"><label class="form-label fw-semibold" for="type">Type</label>
        <select class="form-select" id="type" name="type">
            <option value="topup"<?= ($values['type'] ?? '') === 'topup' ? ' selected' : '' ?>>Top-up (credit)</option>
            <option value="adjustment"<?= ($values['type'] ?? '') === 'adjustment' ? ' selected' : '' ?>>Adjustment (+/-)</option>
            <option value="refund"<?= ($values['type'] ?? '') === 'refund' ? ' selected' : '' ?>>Refund (debit)</option>
        </select></div>
    <div class="mb-3"><label class="form-label fw-semibold" for="amount">Amount</label>
        <input class="form-control" id="amount" name="amount" value="<?= e((string) ($values['amount'] ?? '')) ?>" inputmode="decimal" maxlength="20" required>
        <div class="form-text">Positive decimal, up to 6 places. Adjustments may be negative.</div></div>
    <div class="mb-3"><label class="form-label fw-semibold" for="description">Description / reason</label>
        <input class="form-control" id="description" name="description" value="<?= e((string) ($values['description'] ?? '')) ?>" maxlength="200" required></div>
    <div class="mb-3<?= $isProvider ? ' d-none' : '' ?>" id="refill-pane-email" data-refill-pane="customer">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="send_email" name="send_email" value="1"<?= $sendEmail ? ' checked' : '' ?><?= ($refillEmailsEnabled && !$isProvider) ? '' : ' disabled' ?>>
            <label class="form-check-label" for="send_email">Send email upon refill</label>
        </div>
        <?php if ($refillEmailsEnabled): ?>
            <div class="form-text">Sends only if the user has a valid email address and has not opted out on My profile.</div>
        <?php else: ?>
            <div class="form-text">Enable SMTP and “Allow refill emails” under Settings → Email first.</div>
        <?php endif; ?>
    </div>
    <button class="btn btn-primary" type="submit" data-once><i class="bi bi-wallet2 me-1"></i>Post entry</button>
</form>
<script>
(function () {
  var sel = document.getElementById('target');
  var custPane = document.getElementById('refill-pane-customer');
  var provPane = document.getElementById('refill-pane-provider');
  var emailPane = document.getElementById('refill-pane-email');
  var cust = document.getElementById('customer_id');
  var prov = document.getElementById('carrier_id');
  var email = document.getElementById('send_email');
  if (!sel || !custPane || !provPane || !cust || !prov) return;

  var sync = function () {
    var provider = sel.value === 'provider';
    custPane.classList.toggle('d-none', provider);
    provPane.classList.toggle('d-none', !provider);
    if (emailPane) emailPane.classList.toggle('d-none', provider);

    cust.disabled = provider;
    cust.required = !provider;
    prov.disabled = !provider;
    prov.required = provider;

    if (provider) {
      cust.value = '';
      if (email) { email.checked = false; email.disabled = true; }
    } else {
      prov.value = '';
      if (email) email.disabled = <?= $refillEmailsEnabled ? 'false' : 'true' ?>;
    }
  };

  sel.addEventListener('change', sync);
  sync();
})();
</script>
<?php endif; ?>
