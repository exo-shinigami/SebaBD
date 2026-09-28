<?php
/**
 * SebaBD — reports hub.
 *
 * Lists every report in reports/bootstrap.php. Cards the signed-in role may not
 * open are shown locked, so it is obvious why a report is missing.
 */
require_once __DIR__ . '/reports/bootstrap.php';

$user      = require_staff();
$catalogue = report_catalogue();

/* The version the library actually ships with (composer.json of the package). */
$koolReportVersion = '6.7.1';
$composerFile      = project_path('vendor/koolreport/core/composer.json');
if (is_file($composerFile)) {
    $composer          = json_decode((string) file_get_contents($composerFile), true);
    $koolReportVersion = $composer['version'] ?? $koolReportVersion;
}

$cfg    = require project_path('config.php');
$dbName = $cfg['db']['name'];
$dbUp   = db() !== null;
$server = $dbUp ? (string) db()->query('SELECT VERSION()')->fetchColumn() : 'not connected';

echo partial_render('partials/header.php', ['page_title' => 'Reports', 'active_nav' => 'reports']);
?>

<section class="report-lead py-4 mb-4">
    <div class="container">
        <span class="section-label">Reports</span>
        <h1 class="h3 fw-bold text-white mb-1">SebaBD business intelligence</h1>
        <p class="mb-0 report-lead-sub">Every figure below is read live from the
            <strong><?= htmlspecialchars($dbName) ?></strong> database with KoolReport
            <?= htmlspecialchars($koolReportVersion) ?>.</p>
    </div>
</section>

<div class="container pb-5">
    <div class="row g-3 mb-1">
        <?= report_kpi('Database', htmlspecialchars($dbName), htmlspecialchars($server), 'fa-database') ?>
        <?= report_kpi(
            'Connection',
            $dbUp ? 'Online' : 'Offline',
            $dbUp ? 'Shared with the storefront' : 'Start MySQL in the XAMPP control panel',
            'fa-plug-circle-check'
        ) ?>
        <?= report_kpi('Reports in the module', number_format(count($catalogue)), 'Some are limited to specific roles', 'fa-cubes') ?>
        <?= report_kpi("Report date", date('d M Y'), "Warranty and ageing are measured from today", 'fa-calendar-day') ?>
    </div>

    <div class="row g-3">
        <?php foreach ($catalogue as $page => $report): ?>
            <?php $allowed = report_allowed($report, $user); ?>
            <div class="col-md-6 col-xl-4">
                <?php if ($allowed): ?>
                    <a class="report-hub-card" href="<?= htmlspecialchars($page) ?>">
                        <span class="report-hub-icon"><i class="fa-solid <?= htmlspecialchars($report['icon']) ?>"></i></span>
                        <h2 class="h5 fw-bold mb-0"><?= htmlspecialchars($report['title']) ?></h2>
                        <p class="text-muted small mb-0"><?= htmlspecialchars($report['blurb']) ?></p>
                        <span class="fw-semibold small mt-auto report-hub-link">
                            Open report <i class="fa-solid fa-arrow-right ms-1"></i>
                        </span>
                    </a>
                <?php else: ?>
                    <div class="report-hub-card report-hub-locked">
                        <span class="report-hub-icon"><i class="fa-solid fa-lock"></i></span>
                        <h2 class="h5 fw-bold mb-0 text-muted"><?= htmlspecialchars($report['title']) ?></h2>
                        <p class="text-muted small mb-0"><?= htmlspecialchars($report['blurb']) ?></p>
                        <span class="fw-semibold small mt-auto text-muted">
                            Limited to <?= htmlspecialchars(implode(' / ', $report['roles'])) ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <p class="text-muted small mt-4 mb-0">
        Reports are generated with <a href="https://www.koolreport.com" target="_blank" rel="noopener">KoolReport</a>
        (MIT licence) against the same MySQL schema the storefront writes to — see <code>README.md</code> for the
        report-to-table map.
    </p>
</div>

<?= partial_render('partials/footer.php') ?>
