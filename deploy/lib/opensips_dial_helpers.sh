#!/usr/bin/env bash
# Injected by deploy/stages/50_opensips.sh — unified dial helpers (1+2).
# Requires: HAVE_EXEC, _php_dial (php + opcache flags)
# Exports OPENSIPS_*_ROUTE placeholders for opensips.cfg.tpl

_invite_script="/opt/ownswitch/current/bin/dial-invite.php"
[[ -f "${_invite_script}" ]] || _invite_script="/opt/ownswitch-src/bin/dial-invite.php"
_trunk_gate_script="/opt/ownswitch/current/bin/dial-trunk-gate.php"
[[ -f "${_trunk_gate_script}" ]] || _trunk_gate_script="/opt/ownswitch-src/bin/dial-trunk-gate.php"
_cli_script="/opt/ownswitch/current/bin/cli-restrict-check.php"
[[ -f "${_cli_script}" ]] || _cli_script="/opt/ownswitch-src/bin/cli-restrict-check.php"

# --- prepare_customer_dial: one fork (rewrite + rate/PP + SIP limits + SIP CID + flags) ---
if [[ -z "${OPENSIPS_PREPARE_DIAL_ROUTE:-}" ]]; then
  if (( HAVE_EXEC )) && [[ -f "${_invite_script}" ]]; then
    export OPENSIPS_PREPARE_DIAL_ROUTE='route[prepare_customer_dial] {
	if ($dlg_val(os_dial_ready) == "1") {
		return;
	}
	$var(prep_user) = $avp(os_auth_user);
	if ($var(prep_user) == NULL || $(var(prep_user){s.len}) == 0) {
		$var(prep_user) = $au;
	}
	if ($var(prep_user) == NULL || $(var(prep_user){s.len}) == 0) {
		$var(prep_user) = $dlg_val(os_src);
	}
	if ($var(prep_user) == NULL || $(var(prep_user){s.len}) == 0) {
		$var(prep_user) = $fU;
	}
	$acc_extra(src) = $dlg_val(os_src);
	$acc_extra(dst) = $rU;
	if ($dlg_val(os_cli) != NULL && $(dlg_val(os_cli){s.len}) > 0) {
		$acc_extra(cli) = $dlg_val(os_cli);
	} else {
		$acc_extra(cli) = $fU;
	}
	$acc_extra(ip) = $si;
	$var(prep_out) = NULL;
	$avp(os_rplan) = 0;
	if (!exec("env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env @@PHP_DIAL@@ @@INVITE@@ $var(prep_user) $rU", , $var(prep_out))) {
		if ($var(prep_out) =~ "payment_required") {
			xlog("L_WARN", "Payment Required user=$var(prep_user)\n");
			send_reply(402, "Payment Required");
			exit;
		}
		if ($var(prep_out) =~ "congestion") {
			xlog("L_WARN", "SIP Congestion user=$var(prep_user) from=$si\n");
			acc_db_request("503 Congestion", "acc");
			send_reply(503, "Congestion");
			exit;
		}
		xlog("L_WARN", "Unrated Destination user=$var(prep_user) dst=$rU out=$var(prep_out)\n");
		acc_db_request("403 Unrated Destination", "acc");
		send_reply(403, "Unrated Destination");
		exit;
	}
	if ($var(prep_out) == NULL || $(var(prep_out){s.len}) < 5) {
		acc_db_request("403 Unrated Destination", "acc");
		send_reply(403, "Unrated Destination");
		exit;
	}
	# Profile AVPs (fill missing only — digest path may already have load_credentials)
	$var(p_dnc) = $(var(prep_out){param.value,dncid});
	if (($avp(os_dncid) == NULL || $(avp(os_dncid){s.len}) == 0) && $var(p_dnc) != NULL) {
		$avp(os_dncid) = $var(p_dnc);
	}
	$var(p_mode) = $(var(prep_out){param.value,cid_mode});
	if (($avp(os_cid_mode) == NULL || $(avp(os_cid_mode){s.len}) == 0) && $var(p_mode) != NULL) {
		$avp(os_cid_mode) = $var(p_mode);
	}
	$var(p_cid) = $(var(prep_out){param.value,cid});
	if (($avp(os_cid) == NULL || $(avp(os_cid){s.len}) == 0) && $var(p_cid) != NULL) {
		$avp(os_cid) = $var(p_cid);
	}
	$var(p_media) = $(var(prep_out){param.value,media});
	if (($avp(os_media) == NULL || $(avp(os_media){s.len}) == 0) && $var(p_media) != NULL) {
		$avp(os_media) = $var(p_media);
	}
	$var(p_dtmf) = $(var(prep_out){param.value,dtmf});
	if (($avp(os_dtmf) == NULL || $(avp(os_dtmf){s.len}) == 0) && $var(p_dtmf) != NULL) {
		$avp(os_dtmf) = $var(p_dtmf);
	}
	$var(p_allow) = $(var(prep_out){param.value,allow});
	if (($avp(os_allow) == NULL || $(avp(os_allow){s.len}) == 0) && $var(p_allow) != NULL) {
		$avp(os_allow) = $var(p_allow);
	}
	$var(p_min) = $(var(prep_out){param.value,cidmin});
	if (($avp(os_cid_min) == NULL || $(avp(os_cid_min){s.len}) == 0) && $var(p_min) != NULL) {
		$avp(os_cid_min) = $var(p_min);
	}
	$var(p_max) = $(var(prep_out){param.value,cidmax});
	if (($avp(os_cid_max) == NULL || $(avp(os_cid_max){s.len}) == 0) && $var(p_max) != NULL) {
		$avp(os_cid_max) = $var(p_max);
	}
	$var(p_fmt) = $(var(prep_out){param.value,dstfmt});
	if (($avp(os_dstfmt) == NULL || $(avp(os_dstfmt){s.len}) == 0) && $var(p_fmt) != NULL) {
		$avp(os_dstfmt) = $var(p_fmt);
	}
	# Feature flags (skip unused helpers later)
	$dlg_val(has_dnc) = $(var(prep_out){param.value,has_dnc});
	$dlg_val(has_cli) = $(var(prep_out){param.value,has_cli});
	$dlg_val(has_usercidg) = $(var(prep_out){param.value,has_usercidg});
	$dlg_val(has_usercap) = $(var(prep_out){param.value,has_usercap});
	$dlg_val(has_siplim) = $(var(prep_out){param.value,has_siplim});
	$dlg_val(has_dstmap) = $(var(prep_out){param.value,has_dstmap});
	# Rewritten dial digits (before plan-prefix strip)
	$var(pd) = $(var(prep_out){param.value,dst});
	if ($var(pd) != NULL && $(var(pd){s.len}) >= 3 && $var(pd) =~ "^[0-9]+$") {
		$rU = $var(pd);
	}
	$var(rp) = $(var(prep_out){param.value,rplan});
	if ($var(rp) != NULL && $(var(rp){s.len}) > 0 && $var(rp) =~ "^[0-9]+$") {
		$avp(os_rplan) = $(var(rp){s.int});
	}
	$var(pp) = $(var(prep_out){param.value,dial_prefix});
	if ($var(pp) != NULL && $(var(pp){s.len}) > 0 && $var(pp) =~ "^[0-9]+$") {
		$dlg_val(os_pp) = $var(pp);
		$acc_extra(pp) = $var(pp);
	}
	$var(rd) = $(var(prep_out){param.value,route_dst});
	if ($var(rd) != NULL && $(var(rd){s.len}) >= 3 && $var(rd) =~ "^[0-9]+$") {
		$rU = $var(rd);
		$dlg_val(os_dst) = $var(rd);
		$acc_extra(dst) = $var(rd);
	} else {
		$dlg_val(os_dst) = $rU;
		$acc_extra(dst) = $rU;
	}
	$var(scid) = $(var(prep_out){param.value,sip_cid});
	if ($var(scid) != NULL && $(var(scid){s.len}) >= 3) {
		$dlg_val(os_sip_cid) = $var(scid);
	}
	$dlg_val(os_dial_ready) = "1";
	$dlg_val(os_sip_lim_done) = "1";
	xlog("L_NOTICE", "dial-invite OK user=$var(prep_user) dst=$rU pp=$dlg_val(os_pp) tg=$avp(os_rplan) dnc=$dlg_val(has_dnc) cli=$dlg_val(has_cli) cidg=$dlg_val(has_usercidg) cap=$dlg_val(has_usercap) lim=$dlg_val(has_siplim)\n");
}'
    chmod 644 "${_invite_script}" 2>/dev/null || true
    usermod -aG ownswitch opensips 2>/dev/null || true
    log_info "Dial invite (unified): exec → ${_invite_script}"
  else
    export OPENSIPS_PREPARE_DIAL_ROUTE='route[prepare_customer_dial] {
	xlog("L_WARN", "dial-invite helper missing — refusing outbound\n");
	send_reply(500, "Dial helper missing");
	exit;
}'
    log_warn "Dial invite: exec/dial-invite.php missing"
  fi
fi

# Legacy rewrite/rate/limits become no-ops after prepare (local AOR still uses check_sip_limits)
if [[ -z "${OPENSIPS_DST_REWRITE_ROUTE:-}" ]]; then
  export OPENSIPS_DST_REWRITE_ROUTE='route[apply_sip_dst_rewrite] {
	return;
}'
fi
if [[ -z "${OPENSIPS_CUSTOMER_RATE_ROUTE:-}" ]]; then
  export OPENSIPS_CUSTOMER_RATE_ROUTE='route[check_customer_rate] {
	if ($dlg_val(os_dial_ready) == "1") {
		return;
	}
	xlog("L_WARN", "check_customer_rate without prepare_customer_dial\n");
	send_reply(500, "Dial prepare missing");
	exit;
}'
fi
if [[ -z "${OPENSIPS_SIP_LIMIT_ROUTE:-}" ]]; then
  _sip_lim_script="/opt/ownswitch/current/bin/dial-sip-limit-check.php"
  [[ -f "${_sip_lim_script}" ]] || _sip_lim_script="/opt/ownswitch-src/bin/dial-sip-limit-check.php"
  if (( HAVE_EXEC )) && [[ -f "${_sip_lim_script}" ]]; then
    export OPENSIPS_SIP_LIMIT_ROUTE='route[check_sip_limits] {
	if ($dlg_val(os_sip_lim_done) == "1") {
		return;
	}
	if ($dlg_val(has_siplim) == "0") {
		return;
	}
	$var(lim_user) = $avp(os_auth_user);
	if ($var(lim_user) == NULL || $(var(lim_user){s.len}) == 0) {
		$var(lim_user) = $dlg_val(os_src);
	}
	if ($var(lim_user) == NULL || $(var(lim_user){s.len}) == 0) {
		$var(lim_user) = $au;
	}
	if ($var(lim_user) == NULL || $(var(lim_user){s.len}) == 0) {
		return;
	}
	if (!exec("env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env @@PHP_DIAL@@ @@SIP_LIM@@ $var(lim_user)")) {
		xlog("L_WARN", "SIP Congestion user=$var(lim_user) from=$si (channels/CPS)\n");
		acc_db_request("503 Congestion", "acc");
		send_reply(503, "Congestion");
		exit;
	}
}'
    chmod 644 "${_sip_lim_script}" 2>/dev/null || true
  else
    export OPENSIPS_SIP_LIMIT_ROUTE='route[check_sip_limits] {
	return;
}'
  fi
fi

# CallerID: apply dial-invite result; only re-exec when has_usercidg and no cached sip_cid
if [[ -z "${OPENSIPS_CALLERID_ROUTE:-}" ]]; then
  _cid_script="/opt/ownswitch/current/bin/dial-callerid-pick.php"
  [[ -f "${_cid_script}" ]] || _cid_script="/opt/ownswitch-src/bin/dial-callerid-pick.php"
  export OPENSIPS_CALLERID_ROUTE='route[apply_callerid_group] {
	$var(cid_cli) = NULL;
	if ($dlg_val(os_sip_cid) != NULL && $(dlg_val(os_sip_cid){s.len}) >= 3) {
		$var(cid_cli) = $dlg_val(os_sip_cid);
	} else if ($dlg_val(has_usercidg) == "1") {
		$var(cid_user) = $avp(os_auth_user);
		if ($var(cid_user) == NULL || $(var(cid_user){s.len}) == 0) {
			$var(cid_user) = $dlg_val(os_src);
		}
		if ($var(cid_user) == NULL || $(var(cid_user){s.len}) == 0) {
			$var(cid_user) = $au;
		}
		if ($var(cid_user) == NULL || $(var(cid_user){s.len}) == 0) {
			return;
		}
		$var(cid_dst) = $dlg_val(os_dst);
		if ($var(cid_dst) == NULL || $(var(cid_dst){s.len}) < 3) {
			$var(cid_dst) = $rU;
		}
		$var(cid_out) = NULL;
		if (!exec("env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env @@PHP_DIAL@@ @@CID@@ $var(cid_user) $var(cid_dst)", , $var(cid_out))) {
			return;
		}
		$var(cid_cli) = $(var(cid_out){re.subst,/[^0-9+]//g});
	} else {
		return;
	}
	if ($var(cid_cli) == NULL || $(var(cid_cli){s.len}) < 3 || $(var(cid_cli){s.len}) > 30) {
		return;
	}
	if (!($var(cid_cli) =~ "^[+0-9]{3,30}$")) {
		return;
	}
	$var(cid_dom) = $fd;
	if ($var(cid_dom) == NULL || $(var(cid_dom){s.len}) == 0) {
		$var(cid_dom) = $td;
	}
	$dlg_val(os_cli) = $var(cid_cli);
	$dlg_val(os_sip_cid) = $var(cid_cli);
	$acc_extra(cli) = $var(cid_cli);
	remove_hf("From");
	append_hf("From: <sip:$var(cid_cli)@$var(cid_dom)>;tag=$ft\r\n");
	remove_hf("P-Asserted-Identity");
	append_hf("P-Asserted-Identity: <sip:$var(cid_cli)@$var(cid_dom)>\r\n");
	remove_hf("Remote-Party-ID");
	append_hf("Remote-Party-ID: <sip:$var(cid_cli)@$var(cid_dom)>;party=calling;privacy=off;screen=yes\r\n");
}'
  [[ -f "${_cid_script}" ]] && chmod 644 "${_cid_script}" 2>/dev/null || true
fi

# Trunk gate: CAP + trunk limits + trunk CID in one fork; skip entirely when nothing configured
if [[ -z "${OPENSIPS_TRUNK_GATE_ROUTE:-}" ]]; then
  if (( HAVE_EXEC )) && [[ -f "${_trunk_gate_script}" ]]; then
    export OPENSIPS_TRUNK_GATE_ROUTE='route[check_trunk_gate] {
	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(tid) = $(avp(dr_gw_attrs){param.value,tid});
	if ($var(tid) == NULL || $(var(tid){s.len}) == 0) {
		return;
	}
	$dlg_val(tid) = $var(tid);
	$var(tcidg) = $(avp(dr_gw_attrs){param.value,cidg});
	if ($var(tcidg) == NULL || $(var(tcidg){s.int}) <= 0) {
		$var(tcidg) = 0;
	}
	# Fast path: no user CAP flag, no trunk cidg — still need trunk CAP/limits lookup in PHP
	# (trunk may have CAP/limits even when SIP user has none). One fork covers all.
	$var(gate_user) = $avp(os_auth_user);
	if ($var(gate_user) == NULL || $(var(gate_user){s.len}) == 0) {
		$var(gate_user) = $dlg_val(os_src);
	}
	if ($var(gate_user) == NULL || $(var(gate_user){s.len}) == 0) {
		$var(gate_user) = $au;
	}
	$var(gate_dst) = $dlg_val(os_dst);
	if ($var(gate_dst) == NULL || $(var(gate_dst){s.len}) < 3) {
		$var(gate_dst) = $rU;
	}
	if ($dlg_val(os_pp) != NULL && $(dlg_val(os_pp){s.len}) > 0) {
		$var(gate_dst) = $dlg_val(os_pp) + $var(gate_dst);
	}
	$var(gate_out) = NULL;
	if (!exec("env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env @@PHP_DIAL@@ @@TRUNK_GATE@@ $var(gate_user) $var(gate_dst) $var(tid) $var(tcidg)", , $var(gate_out))) {
		$var(gate_out) = "DENY_TRUNK exec_failed";
	}
	if ($var(gate_out) =~ "DENY_USER") {
		xlog("L_WARN", "User Rate Cap Exceeded user=$var(gate_user) dst=$var(gate_dst)\n");
		acc_db_request("403 Rate Cap Exceeded", "acc");
		send_reply(403, "Rate Cap Exceeded");
		exit;
	}
	if ($var(gate_out) =~ "DENY_LIMIT") {
		xlog("L_WARN", "Trunk capacity exceeded tid=$var(tid) — trying next route\n");
		if ($var(trk_lim_tries) == NULL) {
			$var(trk_lim_tries) = 0;
		}
		$var(trk_lim_tries) = $(var(trk_lim_tries){s.int}) + 1;
		if ($(var(trk_lim_tries){s.int}) < 8 && use_next_gw()) {
			route(check_dnc);
			route(apply_ruri_strip);
			route(restore_user_cli);
			route(apply_sip_cid);
			route(apply_callerid_group);
			route(check_cid_len);
			route(apply_trunk_attrs);
			route(check_trunk_gate);
			return;
		}
		acc_db_request("503 Congestion", "acc");
		send_reply(503, "Congestion");
		exit;
	}
	if ($var(gate_out) =~ "DENY_TRUNK") {
		xlog("L_WARN", "Trunk Rate Cap Exceeded user=$var(gate_user) dst=$var(gate_dst) trunk=$var(tid) — next gw\n");
		if ($var(cap_tries) == NULL) {
			$var(cap_tries) = 0;
		}
		$var(cap_tries) = $(var(cap_tries){s.int}) + 1;
		if ($(var(cap_tries){s.int}) < 8 && use_next_gw()) {
			route(check_dnc);
			route(apply_ruri_strip);
			route(restore_user_cli);
			route(apply_sip_cid);
			route(apply_callerid_group);
			route(check_cid_len);
			route(apply_trunk_attrs);
			route(check_trunk_gate);
			return;
		}
		acc_db_request("403 Rate Cap Exceeded", "acc");
		send_reply(403, "Rate Cap Exceeded");
		exit;
	}
	$var(tcid_cli) = $(var(gate_out){param.value,trunk_cid});
	if ($var(tcid_cli) == NULL || $(var(tcid_cli){s.len}) < 3) {
		return;
	}
	$var(tcid_cli) = $(var(tcid_cli){re.subst,/[^0-9+]//g});
	if ($var(tcid_cli) == NULL || $(var(tcid_cli){s.len}) < 3 || $(var(tcid_cli){s.len}) > 30) {
		return;
	}
	if (!($var(tcid_cli) =~ "^[+0-9]{3,30}$")) {
		return;
	}
	$var(tcid_dom) = $fd;
	if ($var(tcid_dom) == NULL || $(var(tcid_dom){s.len}) == 0) {
		$var(tcid_dom) = $td;
	}
	$var(tfd) = $(avp(dr_gw_attrs){param.value,fd});
	if ($var(tfd) != NULL && $(var(tfd){s.len}) > 0) {
		$var(tcid_dom) = $(var(tfd){s.unescape.param});
	}
	$dlg_val(os_cli) = $var(tcid_cli);
	$acc_extra(cli) = $var(tcid_cli);
	$dlg_val(os_cli_forced) = "1";
	remove_hf("From");
	append_hf("From: <sip:$var(tcid_cli)@$var(tcid_dom)>;tag=$ft\r\n");
	remove_hf("P-Asserted-Identity");
	append_hf("P-Asserted-Identity: <sip:$var(tcid_cli)@$var(tcid_dom)>\r\n");
	remove_hf("Remote-Party-ID");
	append_hf("Remote-Party-ID: <sip:$var(tcid_cli)@$var(tcid_dom)>;party=calling;privacy=off;screen=yes\r\n");
}'
    chmod 644 "${_trunk_gate_script}" 2>/dev/null || true
    log_info "Trunk gate (unified): exec → ${_trunk_gate_script}"
  else
    export OPENSIPS_TRUNK_GATE_ROUTE='route[check_trunk_gate] {
	return;
}'
    log_warn "Trunk gate: dial-trunk-gate.php missing — stub"
  fi
fi

# Legacy stubs replaced by trunk gate
if [[ -z "${OPENSIPS_RATE_CAP_ROUTE:-}" ]]; then
  export OPENSIPS_RATE_CAP_ROUTE='route[check_rate_cap] {
	return;
}'
fi
if [[ -z "${OPENSIPS_TRUNK_LIMIT_ROUTE:-}" ]]; then
  export OPENSIPS_TRUNK_LIMIT_ROUTE='route[check_trunk_limits] {
	return;
}'
fi
if [[ -z "${OPENSIPS_TRUNK_CALLERID_ROUTE:-}" ]]; then
  export OPENSIPS_TRUNK_CALLERID_ROUTE='route[apply_trunk_callerid] {
	return;
}'
fi

# CLI restriction: skip when dial-invite reported no lists assigned
if [[ -z "${OPENSIPS_CLI_RESTRICT_ROUTE:-}" ]]; then
  if (( HAVE_EXEC )) && [[ -f "${_cli_script}" ]]; then
    chmod 644 "${_cli_script}" 2>/dev/null || true
    export OPENSIPS_CLI_RESTRICT_ROUTE='route[check_cli_restriction] {
	if ($dlg_val(has_cli) == "0") {
		return;
	}
	$var(cli_user) = $au;
	if ($var(cli_user) == NULL || $(var(cli_user){s.len}) == 0) {
		$var(cli_user) = $dlg_val(os_src);
	}
	if ($var(cli_user) == NULL || $(var(cli_user){s.len}) == 0) {
		return;
	}
	$var(cli_digits) = $(dlg_val(os_cli){re.subst,/[^0-9]//g});
	if ($var(cli_digits) == NULL || $(var(cli_digits){s.len}) < 3) {
		$var(cli_digits) = $(fU{re.subst,/[^0-9]//g});
	}
	if ($var(cli_digits) == NULL || $(var(cli_digits){s.len}) < 3) {
		return;
	}
	if (!exec("env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env @@PHP_DIAL@@ @@CLI@@ $var(cli_user) $var(cli_digits)")) {
		xlog("L_WARN", "CLI restricted user=$var(cli_user) cli=$var(cli_digits)\n");
		acc_db_request("403 CLI restricted", "acc");
		send_reply(403, "CLI restricted");
		exit;
	}
}'
    log_info "CLI restriction: exec → ${_cli_script} (skip when has_cli=0)"
  else
    export OPENSIPS_CLI_RESTRICT_ROUTE='route[check_cli_restriction] {
	return;
}'
  fi
fi


_os_dial_expand() {
  local s="$1"
  s="${s//@@PHP_DIAL@@/${_php_dial}}"
  s="${s//@@INVITE@@/${_invite_script}}"
  s="${s//@@TRUNK_GATE@@/${_trunk_gate_script}}"
  s="${s//@@CLI@@/${_cli_script}}"
  s="${s//@@CID@@/${_cid_script}}"
  s="${s//@@SIP_LIM@@/${_sip_lim_script}}"
  printf '%s' "$s"
}
OPENSIPS_PREPARE_DIAL_ROUTE="$(_os_dial_expand "${OPENSIPS_PREPARE_DIAL_ROUTE}")"
OPENSIPS_TRUNK_GATE_ROUTE="$(_os_dial_expand "${OPENSIPS_TRUNK_GATE_ROUTE}")"
OPENSIPS_SIP_LIMIT_ROUTE="$(_os_dial_expand "${OPENSIPS_SIP_LIMIT_ROUTE}")"
OPENSIPS_CALLERID_ROUTE="$(_os_dial_expand "${OPENSIPS_CALLERID_ROUTE}")"
OPENSIPS_CLI_RESTRICT_ROUTE="$(_os_dial_expand "${OPENSIPS_CLI_RESTRICT_ROUTE}")"

export OPENSIPS_PREPARE_DIAL_ROUTE OPENSIPS_TRUNK_GATE_ROUTE
export OPENSIPS_DST_REWRITE_ROUTE OPENSIPS_CUSTOMER_RATE_ROUTE OPENSIPS_SIP_LIMIT_ROUTE
export OPENSIPS_CALLERID_ROUTE OPENSIPS_RATE_CAP_ROUTE OPENSIPS_TRUNK_LIMIT_ROUTE OPENSIPS_TRUNK_CALLERID_ROUTE
export OPENSIPS_CLI_RESTRICT_ROUTE
