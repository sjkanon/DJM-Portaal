<?php

/**
 * Beheerder uitloggen. De deelnemersessie (portaal) blijft ongemoeid.
 *
 * Alleen via POST met een geldig CSRF-token; zie de toelichting in
 * ../logout.php.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/layout.php';
vereis_installatie();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url(beheerder_ingelogd() ? 'admin/index.php' : 'admin/login.php'));
    exit;
}

vereis_csrf();

beheerder_uitloggen();
flash('info', 'U bent uitgelogd uit het beheer.');

header('Location: ' . url('admin/login.php'));
exit;
