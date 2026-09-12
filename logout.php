<?php

/**
 * Uitloggen: sessie vernietigen, onthoud-cookie intrekken en terug naar het
 * inlogscherm.
 *
 * Alleen via POST met een geldig CSRF-token. Uitloggen wijzigt de sessie, en
 * zonder die eis kan een willekeurige andere website met een enkel plaatje
 * (<img src="…/logout.php">) een bezoeker midden in zijn download uitloggen.
 *
 * De melding gaat via ?uitgelogd=1 in plaats van via flash(): een flash wordt in
 * de sessie bewaard en die is er na destroy_current_session() niet meer.
 * index.php toont deze parameter als melding.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Een GET hoort hier niet; stuur terug zonder iets te wijzigen.
    header('Location: ' . url(deelnemer_ingelogd() ? 'portaal/index.php' : 'index.php'));
    exit;
}

vereis_csrf();

deelnemer_uitloggen();

header('Location: ' . url('index.php?uitgelogd=1'));
exit;
