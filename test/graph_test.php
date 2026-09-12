<?php
/**
 * Controleert de foutafhandeling van de Graph-mailer zonder een echte tenant.
 *
 * Een echte verzending kan pas getest worden met geldige gegevens uit Entra.
 * Wat hier wél te testen is: dat ontbrekende of verkeerde gegevens een nette,
 * begrijpelijke melding opleveren, dat er niets crasht, en dat de mislukking
 * in mail_log terechtkomt.
 */

// Deze testscripts horen uitsluitend op de commandoregel te draaien. Ze wijzigen
// of wissen gegevens; wordt de map test/ per ongeluk meegeüpload naar een
// server, dan mag een bezoeker ze nooit via de browser kunnen starten.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['HTTP_HOST']   = 'localhost';
require '/app/config.php';
require '/app/includes/email_helper.php';

$goed = 0;
$fout = 0;

function toets(string $omschrijving, $verwacht, $werkelijk): void
{
    global $goed, $fout;
    $ok = $verwacht === $werkelijk;
    $ok ? $goed++ : $fout++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $omschrijving,
        $ok ? '' : sprintf("\n      verwacht: %s\n      kreeg:    %s",
            var_export($verwacht, true), var_export($werkelijk, true)));
}

function toets_waar(string $omschrijving, $werkelijk): void
{
    toets($omschrijving, true, (bool)$werkelijk);
}

// De oorspronkelijke instellingen bewaren; aan het eind zetten we ze terug.
$origineel = [];
foreach (['email_methode', 'graph_tenant_id', 'graph_client_id', 'graph_client_secret',
          'email_van_adres', 'smtp_host'] as $sleutel) {
    $origineel[$sleutel] = instelling($sleutel, '');
}

echo "\n── Zonder ingevulde gegevens ───────────────────────────────\n";
instelling_opslaan('email_methode', 'graph');
foreach (['graph_tenant_id', 'graph_client_id', 'graph_client_secret'] as $sleutel) {
    instelling_opslaan($sleutel, '');
}
instelling_opslaan('email_van_adres', '');

toets('mail_geconfigureerd() ziet dat het niet af is', false, mail_geconfigureerd());

$fouten = [];
$gelukt = verstuur_testmail('ouder@example.nl', $fouten);
toets('versturen mislukt netjes', false, $gelukt);
toets_waar('met een bruikbare uitleg', count($fouten) > 0
    && str_contains(strtolower(implode(' ', $fouten)), 'ingesteld'));
printf("    melding: %s\n", $fouten[0] ?? '(geen)');

$laatste = db()->query("SELECT status, soort FROM mail_log ORDER BY id DESC LIMIT 1")->fetch();
toets('mislukking staat in het mailboek', 'mislukt', $laatste['status'] ?? '');

echo "\n── Met verzonnen gegevens ──────────────────────────────────\n";
instelling_opslaan('graph_tenant_id', '00000000-0000-0000-0000-000000000000');
instelling_opslaan('graph_client_id', '11111111-1111-1111-1111-111111111111');
instelling_opslaan('graph_client_secret', 'dit-is-geen-geldig-secret');
instelling_opslaan('email_van_adres', 'noreply@voorbeeld.test');

toets('mail_geconfigureerd() ziet het nu als compleet', true, mail_geconfigureerd());

$mailer = mailer_maken();
toets('mailer_maken() levert een GraphMailer', 'GraphMailer', get_class($mailer));

$fouten = [];
$start  = microtime(true);
$gelukt = verstuur_testmail('ouder@example.nl', $fouten);
$duur   = microtime(true) - $start;

toets('versturen mislukt (geen geldige tenant)', false, $gelukt);
toets_waar('zonder vast te lopen (binnen 30 seconden)', $duur < 30);
toets_waar('met minstens één foutmelding', count($fouten) > 0);
printf("    melding: %s\n", mb_substr((string)($fouten[0] ?? '(geen)'), 0, 160));

echo "\n── Diagnose in het beheer ──────────────────────────────────\n";
$diagnose = $mailer->diagnoseConfiguration();
toets_waar('diagnose geeft een array terug', is_array($diagnose));
foreach (['token_ok', 'roles', 'has_mail_send', 'mailbox_status', 'mailbox_conclusief', 'mailbox_hint', 'errors'] as $sleutel) {
    toets("sleutel '$sleutel' aanwezig", true, array_key_exists($sleutel, $diagnose));
}
toets('token niet opgehaald met onzin-gegevens', false, (bool)$diagnose['token_ok']);
toets_waar('met uitleg waarom', count($diagnose['errors']) > 0);
printf("    melding: %s\n", mb_substr((string)($diagnose['errors'][0] ?? '(geen)'), 0, 160));

echo "\n── Instellingen terugzetten ────────────────────────────────\n";
foreach ($origineel as $sleutel => $waarde) {
    instelling_opslaan($sleutel, $waarde);
}
echo "  oorspronkelijke mailinstellingen hersteld\n";

echo "\n────────────────────────────────────────────────────────────\n";
printf("  %d geslaagd, %d mislukt\n\n", $goed, $fout);
exit($fout === 0 ? 0 : 1);
