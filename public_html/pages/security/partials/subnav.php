<?php
/**
 * All Father security sub-navigation.
 */
$securityAction = get('action', 'rate-limits');
if ($securityAction === 'index' || $securityAction === '') {
    $securityAction = 'rate-limits';
}
$secNavItems = [
    'rate-limits' => ['bi-unlock', 'Lockouts'],
    'summary' => ['bi-bar-chart-line', 'Summary'],
    'sms' => ['bi-phone', 'SMS'],
    'email' => ['bi-envelope', 'Email'],
];
?>
<ul class="nav nav-tabs mb-4">
    <?php foreach ($secNavItems as $secAct => [$secIcon, $secLabel]): ?>
    <li class="nav-item">
        <a class="nav-link <?= $securityAction === $secAct ? 'active' : '' ?>"
           href="<?= APP_URL ?>/?page=security&action=<?= e($secAct) ?>">
            <i class="bi <?= e($secIcon) ?> me-1"></i><?= e($secLabel) ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>
