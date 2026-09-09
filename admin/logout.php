<?php

/**
 * Beheerder uitloggen. De deelnemersessie (portaal) blijft ongemoeid.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/layout.php';
vereis_installatie();

beheerder_uitloggen();
flash('info', 'U bent uitgelogd uit het beheer.');

header('Location: ' . url('admin/login.php'));
exit;
