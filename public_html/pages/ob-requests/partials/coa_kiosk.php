<?php
/**
 * LOKA - CoA Signing Kiosk shared markup (Plan #25)
 *
 * Standalone full-HTML kiosk used by coa-sign.php for all states
 * (form / thank-you / dead link). Trust-first certificate, finger-sized pad.
 *
 * Expected variables:
 *   $kioskState      'form' | 'done' | 'dead'
 *   $kioskOb         ?object  slip (pass_slip_no, ob_date, employee_name, purpose)
 *   $kioskError      ?string  dead-link reason
 *   $kioskErrors     list<string> validation errors
 *   $kioskToken      string   one-time token (hidden field)
 *   $kioskAction     string   form action (current URL — keeps the query string)
 *   $kioskDays       int      token validity in days
 *   $kioskAppearance string   certify line (escaped)
 *   $kioskReturnUrl  ?string  optional "Return to pass slip" target
 *   $kioskWhoNote    string   small employee/personnel note under the header
 */
$kioskState = $kioskState ?? 'dead';
$kioskOb = $kioskOb ?? null;
$kioskError = $kioskError ?? null;
$kioskErrors = $kioskErrors ?? [];
$kioskToken = $kioskToken ?? '';
$kioskAction = $kioskAction ?? '';
$kioskDays = (int) ($kioskDays ?? 7);
$kioskAppearance = $kioskAppearance ?? '';
$kioskReturnUrl = $kioskReturnUrl ?? null;
$kioskWhoNote = $kioskWhoNote ?? '';
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Certificate of Appearance — <?= $h($kioskOb->pass_slip_no ?? 'Pass Slip') ?> | <?= $h(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= ASSETS_PATH ?>/css/ob-coa.css?v=<?= e(APP_VERSION) ?>" rel="stylesheet">
    <style>body{background:linear-gradient(160deg,#e8eef5 0%,#d5e0ec 100%);min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:.6rem;}</style>
</head>
<body>
<div class="coa-kiosk">
    <div class="coa-kiosk__header">
        <p class="coa-office">DICT Region II · Tuguegarao City</p>
        <h1 class="coa-title">Certificate of Appearance</h1>
        <?php if ($kioskOb !== null): ?>
        <p class="coa-slipno">Pass Slip No. <?= $h($kioskOb->pass_slip_no) ?> · <?= $h(date('M j, Y', strtotime($kioskOb->ob_date))) ?></p>
        <?php endif; ?>
        <?php if ($kioskWhoNote !== ''): ?>
        <p class="coa-whonote">Personnel: <?= $kioskWhoNote ?></p>
        <?php endif; ?>
    </div>
    <div class="coa-kiosk__body">

        <?php if ($kioskState === 'dead'): ?>
            <div class="coa-dead">
                <i class="bi bi-shield-exclamation"></i>
                <div>
                    <h2>Link Not Usable</h2>
                    <p><?= $h($kioskError ?? 'This signing link is invalid or has expired.') ?></p>
                </div>
            </div>
            <p class="coa-note">Do not release the employee without a valid pass slip. Contact the DICT Region II Motor Pool if needed.</p>

        <?php elseif ($kioskState === 'done'): ?>
            <div class="coa-thanks">
                <div class="coa-thanks__icon"><i class="bi bi-check-lg"></i></div>
                <div>
                    <h2>Thank you!</h2>
                    <p>The Certificate of Appearance acknowledgment has been recorded securely.</p>
                </div>
            </div>
            <p class="coa-note mb-3">The requester has been notified and will finalize the pass slip. You may close this page now.</p>
            <?php if ($kioskReturnUrl !== null): ?>
            <a href="<?= $h($kioskReturnUrl) ?>" class="btn btn-outline-primary coa-return">
                <i class="bi bi-arrow-return-left me-1"></i>Return to pass slip
            </a>
            <?php endif; ?>

        <?php else: ?>
            <div class="coa-certify">
                <i class="bi bi-shield-check"></i>
                <div><?= $kioskAppearance ?></div>
            </div>

            <?php foreach ($kioskErrors as $er): ?>
                <div class="coa-errors"><div class="coa-error-item"><?= $h($er) ?></div></div>
            <?php endforeach; ?>

            <form method="POST" action="<?= $h($kioskAction) ?>" id="coaForm" novalidate>
                <input type="hidden" name="token" value="<?= $h($kioskToken) ?>">

                <div class="coa-field">
                    <label class="form-label" for="coaOffice">Office / Establishment <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-lg" id="coaOffice" name="coa_office"
                        maxlength="200" autocomplete="organization" required>
                </div>

                <div class="coa-field">
                    <label class="form-label" for="coaRep">Representative Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-lg" id="coaRep" name="coa_representative"
                        maxlength="150" autocomplete="name" required>
                </div>

                <div class="coa-field">
                    <label class="form-label" for="coaPurpose">Purpose of visit <span class="text-muted">(optional)</span></label>
                    <input type="text" class="form-control" id="coaPurpose" name="coa_purpose"
                        maxlength="300" value="<?= $h($kioskOb->purpose ?? '') ?>">
                </div>

                <div class="coa-row-times">
                    <div class="coa-field">
                        <label class="form-label" for="coaFrom">From <span class="text-danger">*</span></label>
                        <input type="time" class="form-control" id="coaFrom" name="coa_time_from" required>
                    </div>
                    <div class="coa-field">
                        <label class="form-label" for="coaTo">To <span class="text-danger">*</span></label>
                        <input type="time" class="form-control" id="coaTo" name="coa_time_to" required>
                    </div>
                </div>

                <div class="coa-field">
                    <label class="form-label" for="coaMobile">Mobile number <span class="text-muted">(for future validation)</span></label>
                    <input type="tel" class="form-control" id="coaMobile" name="coa_mobile"
                        maxlength="20" autocomplete="tel" placeholder="09171234567">
                </div>

                <div class="coa-field">
                    <label class="form-label" for="coaEmail">Official email <span class="text-muted">(for future validation)</span></label>
                    <input type="email" class="form-control" id="coaEmail" name="coa_email"
                        maxlength="150" autocomplete="email" placeholder="name@office.gov.ph">
                    <div class="form-text small">Provide at least one — mobile and/or official email. DICT may contact you to validate this record.</div>
                </div>

                <div class="coa-field">
                    <div class="d-flex align-items-start gap-2 border rounded p-3 bg-white">
                        <input class="form-check-input flex-shrink-0 mt-1" type="checkbox" value="1" id="coaAcknowledge" name="coa_acknowledge" required>
                        <label class="form-check-label small" for="coaAcknowledge">
                            <strong>Proof of service.</strong> I confirm that the DICT Regional Office II personnel named above
                            appeared at our office on the date and times stated, and I am authorized to acknowledge their visit.
                        </label>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary coa-submit">
                    <i class="bi bi-check-lg me-1"></i>Acknowledge &amp; Submit
                </button>
                <p class="coa-note"><i class="bi bi-lock me-1"></i>One-time link · expires after use or in <?= $kioskDays ?> day(s) · submit time and device are recorded</p>
            </form>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
