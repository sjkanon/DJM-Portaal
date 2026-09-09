<?php

/**
 * Uitloggen: sessie vernietigen, onthoud-cookie intrekken en terug naar het
 * inlogscherm.
 *
 * De melding gaat via ?uitgelogd=1 in plaats van via flash(): een flash wordt in
 * de sessie bewaard en die is er na destroy_current_session() niet meer.
 * index.php toont deze parameter als melding.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

deelnemer_uitloggen();

header('Location: ' . url('index.php?uitgelogd=1'));
exit;
