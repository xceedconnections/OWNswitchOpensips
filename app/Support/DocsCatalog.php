<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/**
 * In-portal operator documentation (Settings → Documentation).
 * Keep articles practical: what the option does, where it lives, and how it affects calls/CDR.
 */
final class DocsCatalog
{
    /**
     * @return list<array{slug:string,title:string,summary:string,group:string}>
     */
    public static function index(): array
    {
        $out = [];
        foreach (self::articles() as $slug => $a) {
            $out[] = [
                'slug' => $slug,
                'title' => $a['title'],
                'summary' => $a['summary'],
                'group' => $a['group'],
            ];
        }
        return $out;
    }

    /** @return array{title:string,summary:string,group:string,sections:list<array{heading:string,html:string}>}|null */
    public static function get(string $slug): ?array
    {
        return self::articles()[$slug] ?? null;
    }

    /**
     * @return array<string,array{title:string,summary:string,group:string,sections:list<array{heading:string,html:string}>}>
     */
    private static function articles(): array
    {
        return [
            'overview' => [
                'title' => 'Ownswitch overview',
                'summary' => 'How the switch is organised: customers, SIP users, trunks, rates, routing, and CDRs.',
                'group' => 'Getting started',
                'sections' => [
                    [
                        'heading' => 'What Ownswitch is',
                        'html' => '<p>Ownswitch is a multi-tenant VoIP billing switch. Customers authenticate as <strong>SIP users</strong>, calls are routed through <strong>trunks</strong> (carriers), and every answered call produces a <strong>CDR</strong> with sell (customer) and buy (carrier) amounts.</p>
<p>Call control runs on <strong>OpenSIPS</strong> (directory + dialplan via the portal). Billing formulas live in the portal (<code>RatingService</code>) so invoices stay consistent.</p>',
                    ],
                    [
                        'heading' => 'Typical setup order',
                        'html' => '<ol>
<li>Create a <strong>Provider</strong>, then one or more <strong>Trunks</strong>.</li>
<li>Import or enter <strong>Provider rates</strong> (buy) and a customer <strong>Tariff / Plan</strong> (sell).</li>
<li>Build a <strong>Trunk Group</strong> (routing plan) and attach prefixes.</li>
<li>Create a <strong>User</strong> (customer) and a <strong>SIP User</strong>.</li>
<li>Place a test call, then verify <strong>CDR</strong> sell, buy, and margin.</li>
</ol>',
                    ],
                    [
                        'heading' => 'Where help lives in the UI',
                        'html' => '<p>Every form field with a description shows a <i class="bi bi-question-circle"></i> icon. Click it for a popup explaining what that option does. Full topic guides are under <strong>Settings → Documentation</strong>.</p>',
                    ],
                ],
            ],
            'trunks' => [
                'title' => 'Trunks & providers',
                'summary' => 'Carrier connectivity, dial rewrite, CallerID, CAP, DNC, codecs, and Delayed Bye.',
                'group' => 'Routing',
                'sections' => [
                    [
                        'heading' => 'Providers vs trunks',
                        'html' => '<p>A <strong>Provider</strong> is the carrier company. A <strong>Trunk</strong> is one SIP gateway (host/port/auth) under that provider. CDRs store both for profit reports.</p>',
                    ],
                    [
                        'heading' => 'Auth modes',
                        'html' => '<ul>
<li><strong>IP peer</strong> — vendor allowlists your switch IP; no REGISTER.</li>
<li><strong>Outbound REGISTER</strong> — OpenSIPS registers with username/password.</li>
<li><strong>Invite digest</strong> — digest only when sending INVITE (no persistent REGISTER).</li>
</ul>',
                    ],
                    [
                        'heading' => 'Dial &amp; CLI rewrite',
                        'html' => '<p><strong>Remove prefixes</strong> strips tech prefixes (longest first), then <strong>Add prefix</strong> is applied. <strong>Destination format</strong> can force E.164 with or without <code>+</code>.</p>
<p><strong>From user</strong> forces the outbound CLI toward the vendor and overrides SIP-user CallerID / PBX CLI. CID add/remove adjust digits before that. Min/max CLI length reject bad CLI with SIP 484.</p>',
                    ],
                    [
                        'heading' => 'CAP, DNC, duration',
                        'html' => '<ul>
<li><strong>CAP</strong> — skip this trunk when buy rate for the dialled prefix is above the cap (failover may try the next trunk).</li>
<li><strong>DNC group</strong> — block listed destinations before INVITE to the vendor.</li>
<li><strong>Max call duration</strong> — OpenSIPS <code>max call duration</code> on this gateway leg (0 = off).</li>
</ul>
<p>See also <a href="' . e(url('/docs/delayed-bye')) . '">Delayed Bye / MCD</a>.</p>',
                    ],
                ],
            ],
            'sip-users' => [
                'title' => 'SIP users',
                'summary' => 'Authentication, CallerID, media policy, CPS/channels, CAP, and routing overrides.',
                'group' => 'Clients',
                'sections' => [
                    [
                        'heading' => 'Connection types',
                        'html' => '<ul>
<li><strong>Dynamic</strong> — username/password REGISTER (softphones, many PBXs). Several users may share one public IP; auth uses the Authorization username.</li>
<li><strong>IP based</strong> — fixed allowed IP(s), no digest password. Do not put the same IP on more than one IP-based user.</li>
</ul>',
                    ],
                    [
                        'heading' => 'CallerID',
                        'html' => '<p><strong>CallerID mode</strong> chooses the source of outbound CLI (passthrough, portal forced, display name, RPID, PAI). Optional <strong>CallerID group</strong> overrides by dialled prefix. <strong>CLI restrictions</strong> block listed CLIs with SIP 403.</p>
<p>Trunk <strong>From user</strong> still wins if set on the selected trunk.</p>',
                    ],
                    [
                        'heading' => 'Limits &amp; media',
                        'html' => '<ul>
<li><strong>Call limit (CC)</strong> / <strong>CPS</strong> — OpenSIPS hash limits per SIP user.</li>
<li><strong>Max call duration</strong> — <code>dialog timeout</code>; 0 uses Settings prepaid hard cap.</li>
<li><strong>Media / record</strong> — Direct (bypass), Relay, or Record. Web-Dialer forces relay.</li>
<li><strong>Trunk group</strong> — optional override of the tariff’s routing plan.</li>
</ul>',
                    ],
                ],
            ],
            'delayed-bye' => [
                'title' => 'Delayed Bye & MCD',
                'summary' => 'Hold BYE toward the vendor to meet a minimum duration; optional buy-cost extension on CDRs.',
                'group' => 'Routing',
                'sections' => [
                    [
                        'heading' => 'What it does',
                        'html' => '<p>Configured on the <strong>trunk</strong> (Delayed Bye tab). When the customer hangs up early, OpenSIPS keeps the carrier leg alive a bit longer so the vendor’s CDR can show a longer connected time.</p>
<p><strong>Customer sell charges always use real talk time.</strong> Only carrier buy/cost can optionally follow the extended duration.</p>',
                    ],
                    [
                        'heading' => 'Fields',
                        'html' => '<table class="table table-sm table-bordered">
<thead><tr><th>Field</th><th>Meaning</th></tr></thead>
<tbody>
<tr><td>Delayed Bye (s)</td><td>Extra seconds always added after hangup.</td></tr>
<tr><td>Delayed Bye MCD (s)</td><td>Minimum target duration. Extension includes <code>max(0, MCD − actual)</code>.</td></tr>
<tr><td>Count Delayed duration in Buying</td><td>If checked, CDR carrier cost uses extended seconds. If unchecked, buy uses actual only.</td></tr>
</tbody>
</table>
<p>Extension formula: <code>max(0, MCD − actual) + Delayed Bye</code><br>
Connected toward vendor ≈ <code>max(actual, MCD) + Delayed Bye</code></p>',
                    ],
                    [
                        'heading' => 'Examples (MCD = 25, Delayed Bye = 0)',
                        'html' => '<table class="table table-sm table-bordered">
<thead><tr><th>Actual</th><th>Extension</th><th>Carrier sees ≈</th></tr></thead>
<tbody>
<tr><td>5 s</td><td>20 s</td><td>25 s</td></tr>
<tr><td>10 s</td><td>15 s</td><td>25 s</td></tr>
<tr><td>20 s</td><td>5 s</td><td>25 s</td></tr>
<tr><td>25 s</td><td>0 s</td><td>25 s</td></tr>
<tr><td>40 s</td><td>0 s</td><td>40 s</td></tr>
</tbody>
</table>',
                    ],
                    [
                        'heading' => 'Wiring / enable on the server',
                        'html' => '<pre class="bg-light border rounded p-3 small mb-2">cd /opt/ownswitch-src
git fetch origin main &amp;&amp; git reset --hard origin/main
sudo bash deploy/refresh-ui.sh
sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \\
  php /opt/ownswitch/current/bin/migrate.php
sudo bash deploy/fix-delayed-bye.sh
sudo bash deploy/fix-esl-auth.sh
</pre>
<p>Confirm <code>mod_lua</code> is loaded and <code>/usr/local/freeswitch/share/freeswitch/scripts/delayed_bye.lua</code> exists. Then set the three trunk fields and place a short test call.</p>',
                    ],
                    [
                        'heading' => 'CDR &amp; profit',
                        'html' => '<ul>
<li><strong>Actual duration</strong> — real talk time (customer billing base).</li>
<li><strong>Billable customer</strong> — after customer I/S increments on actual.</li>
<li><strong>Billable carrier / carrier cost</strong> — after carrier I/S on actual, or on extended duration when “Count Delayed duration in Buying” is on.</li>
<li><strong>Gross margin</strong> — sell − buy (so enabling the buying checkbox lowers margin when MCD extends short calls).</li>
</ul>
<p>If the vendor does <em>not</em> bill the delayed seconds, leave the checkbox off.</p>',
                    ],
                ],
            ],
            'billing-cdr' => [
                'title' => 'Billing & CDRs',
                'summary' => 'Sell vs buy, increments, prepaid, and how reports show profit.',
                'group' => 'Billing',
                'sections' => [
                    [
                        'heading' => 'Billable duration',
                        'html' => '<p>Given connected seconds <code>D</code>, initial <code>I</code>, subsequent <code>S</code>:</p>
<ul>
<li>If <code>D = 0</code> → billable 0 (no connect fee unless policy says otherwise).</li>
<li>If <code>D ≤ I</code> → billable = <code>I</code>.</li>
<li>Else → <code>I + ceil((D − I) / S) × S</code>.</li>
</ul>
<p>Charge = rate_per_minute × billable_seconds / 60 + connection fee. Full rules: repository <code>BILLING_RULES.md</code>.</p>',
                    ],
                    [
                        'heading' => 'Customer vs carrier',
                        'html' => '<p>Each answered call rates <strong>twice</strong>: customer tariff (sell) and trunk/provider rate (buy). Margin = sell − buy. Delayed Bye can extend only the buy side when the trunk checkbox is enabled.</p>',
                    ],
                    [
                        'heading' => 'Prepaid',
                        'html' => '<p>Before routing, Ownswitch reserves credit and may apply a max talk time (<code>dialog timeout</code>). On hangup the reservation is released and the final charge is posted to the ledger.</p>',
                    ],
                ],
            ],
            'routing' => [
                'title' => 'Trunk groups & routing',
                'summary' => 'How destinations pick trunks, failover, ASR/LCR ordering, and the simulator.',
                'group' => 'Routing',
                'sections' => [
                    [
                        'heading' => 'Trunk groups',
                        'html' => '<p>A trunk group (routing plan) is an ordered / strategy-based list of trunks for matching prefixes. Strategies include priority, LCR (cheapest buy), and ASR-aware ordering.</p>',
                    ],
                    [
                        'heading' => 'Call path',
                        'html' => '<ol>
<li>Authenticate SIP user</li>
<li>Normalize destination; apply plan prefixes / tariff</li>
<li>DNC / CLI / CAP checks</li>
<li>Build OpenSIPS bridge string with failover trunks</li>
<li>On answer/hangup → XML CDR → rate → ledger</li>
</ol>
<p>Use <strong>Routes → Simulator</strong> to preview which trunk would be chosen without placing a call.</p>',
                    ],
                ],
            ],
            'settings' => [
                'title' => 'System settings',
                'summary' => 'Branding, codecs, capacity, failover codes, timezone, kill switch.',
                'group' => 'Administration',
                'sections' => [
                    [
                        'heading' => 'Key switches',
                        'html' => '<ul>
<li><strong>Kill switch</strong> — reject new outbound calls immediately.</li>
<li><strong>Default codecs</strong> — used when SIP user / trunk codec list is empty.</li>
<li><strong>Failover SIP codes</strong> — continue to the next trunk for these vendor SIP replies (408, 480, …) and for <strong>TCAP</strong> (trunk rate CAP). <strong>UCAP</strong> (user rate CAP) never continues to the next trunk.</li>
<li><strong>Prepaid hard max call duration</strong> — default max when SIP user max is 0.</li>
<li><strong>System timezone</strong> — default for users who follow system time.</li>
</ul>
<p>Click the <i class="bi bi-question-circle"></i> next to each Settings field for field-level detail.</p>',
                    ],
                ],
            ],
            'field-help' => [
                'title' => 'Using field help (?)',
                'summary' => 'How popup help works on every form field.',
                'group' => 'Getting started',
                'sections' => [
                    [
                        'heading' => 'Popup help',
                        'html' => '<p>Labels that have documentation show a circular <strong>?</strong> icon. Click (or focus) the icon to open a short explanation. Click elsewhere to dismiss.</p>
<p>Help text is the same source used by operators and by this Documentation section — keep it accurate when you change behaviour.</p>',
                    ],
                ],
            ],
        ];
    }
}
