<?php

use TheJenos\SmartPiiRedactor\SmartPiiRedactor;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

$section1 = <<<'TEXT'
    --------------------------------------------------------------------------------
    SECTION 1 — SECURITY INCIDENT REPORT
    --------------------------------------------------------------------------------

    Ref: INC-2026-0417
    Prepared by: Dana Whitcombe, Principal Security Engineer, Halcyon Data Systems
    Contact: dana.whitcombe@halcyon-data.example.com / +1 (503) 555-0142
    Date: 14 March 2026
    Distribution: Internal — Security, Legal, and the Office of the CISO

    At 02:14 UTC an automated rule fired on the edge tier of our Rotterdam,
    Netherlands facility. The originating address was 203.0.113.47, which had not
    appeared in traffic logs in the preceding ninety days. Within four minutes the
    same actor was observed from 198.51.100.219 and, shortly after, from the IPv6
    address 2001:db8:4f2a::9c1 — a rotation pattern consistent with a commodity
    proxy pool rather than a targeted operation.

    The intruder authenticated against the internal reporting gateway at
    https://reports.halcyon-data.example.com/v2/export using a service credential
    that should have been rotated in January. The credential in question was the
    API key sk-live-Xq7Vn2Rp9TgL4wYb8ZmK3Hs6dE1a, issued to the batch export job
    maintained by Marcus Adeyemi (marcus.adeyemi@halcyon-data.example.com,
    direct line +1 503 555 0188).

    Marcus has confirmed the key was committed to a private repository in November
    2025 and never removed from history. The commit also contained a secondary
    credential, AKIAZZ7EXAMPLE4QK2NP, paired with the secret
    wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY, both of which have been revoked.

    Session capture shows the following request header replayed eleven times:

        GET /v2/export?scope=full&since=2026-01-01 HTTP/1.1
        Host: reports.halcyon-data.example.com
        Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJzdmMtZXhwb3J0IiwibmFtZSI6IkJhdGNoIEV4cG9ydCIsImlhdCI6MTc2NzIyNTYwMH0.Kq9Zt3XmVr7LpNc2WsBd6HyF1aQeR8UjT0iOgM4vXnY
        X-Forwarded-For: 203.0.113.47
        User-Agent: python-requests/2.31.0

    The exported archive contained 11,482 customer records. A sample of the affected
    fields is reproduced below under Section 4. Legal counsel at Orinoco Legal LLP
    has been notified; the engagement partner is Aoife Sullivan
    (a.sullivan@orinocolegal.example.org, +353 1 555 0193, offices in Dublin 4,
    Ireland).

    Escalation was routed through our managed detection vendor, Bluepeak Analytics
    of Bengaluru, India. Their on-call analyst, Priya Raghunathan, can be reached at
    priya.raghunathan@bluepeak.example.net or on +91 80 5550 0177. Bluepeak's
    runbook for this class of event is published internally at
    http://wiki.halcyon-data.example.com/security/runbooks/credential-replay.

    Remediation status as of 14 March:
    - All static keys under the `svc-export` identity revoked.
    - Egress from 203.0.113.0/24 and 198.51.100.0/24 blocked at the perimeter.
    - Rotation of the fleet-wide token completed; new bearer token issued to
        the deployment pipeline only and stored in the vault at
        https://vault.halcyon-data.example.com/ui/secrets/svc-export.
    - Notification letters drafted for affected residents of Ashgrove, Ontario
        and Marlowe Heights, Oregon, where regulatory timelines are tightest.

TEXT;

it('can test the smart pii redactor', function () use ($section1) {
    $smartPiiRedactor = App::make(SmartPiiRedactor::class);

    $entities = $smartPiiRedactor->getEntities($section1);

    $replacementKey = Str::random(10);

    $newPrompt = $smartPiiRedactor->mask($section1, $entities, $replacementKey);

    $replacement = $smartPiiRedactor->getCacheReplacement($replacementKey);

    expect($newPrompt)
        ->toBeString()
        ->toContain('[PERSON_0]');

    expect($replacement)
        ->toBeArray()
        ->toHaveKey('[PERSON_0]');
});
