<?php

/**
 * E-mail via de Microsoft Graph API (app-only), met SMTP als terugvaloptie.
 *
 * De klassen SimpleMailer en GraphMailer zijn overgenomen uit het Klantenportaal
 * en werken zonder externe libraries (alleen cURL en fsockopen).
 *
 * Publieke functies:
 *   verstuur_mail()             — lage laag, logt in mail_log
 *   verstuur_inlogcode_mail()   — de eenmalige inlogcode
 *   verstuur_uitnodiging_mail() — "uw video staat klaar"
 *   verstuur_testmail()         — vanuit Beheer > Instellingen
 *   mailer_maken()              — geconfigureerde mailer voor diagnose
 */

if (!function_exists('db')) {
    require_once dirname(__DIR__) . '/config.php';
}
// Voor branding_kleur(): de merkkleur in de mails is dezelfde als op de site.
require_once __DIR__ . '/opmaak.php';

// ─── Configuratie ────────────────────────────────────────────────────────────

/** Actuele mailinstellingen: database wint, .env vult aan. */
function mail_config(): array
{
    return [
        'methode'       => instelling('email_methode', 'graph'),
        'tenant_id'     => instelling('graph_tenant_id', env('GRAPH_TENANT_ID')),
        'client_id'     => instelling('graph_client_id', env('GRAPH_CLIENT_ID')),
        'client_secret' => instelling('graph_client_secret', env('GRAPH_CLIENT_SECRET')),
        'van_adres'     => instelling('email_van_adres', env('MAIL_VAN_ADRES')),
        'van_naam'      => instelling('email_van_naam', env('MAIL_VAN_NAAM', APP_NAME)),
        'smtp_host'     => instelling('smtp_host', env('SMTP_HOST')),
        'smtp_poort'    => (int)instelling('smtp_poort', env('SMTP_POORT', '587')),
        'smtp_beveiliging' => instelling('smtp_beveiliging', env('SMTP_BEVEILIGING', 'tls')),
        'smtp_gebruiker'   => instelling('smtp_gebruikersnaam', env('SMTP_GEBRUIKER')),
        'smtp_wachtwoord'  => instelling('smtp_wachtwoord', env('SMTP_WACHTWOORD')),
    ];
}

/** @return GraphMailer|SimpleMailer */
function mailer_maken(?array $cfg = null)
{
    $cfg ??= mail_config();

    if (($cfg['methode'] ?? 'graph') === 'graph') {
        return new GraphMailer(
            (string)$cfg['tenant_id'],
            (string)$cfg['client_id'],
            (string)$cfg['client_secret'],
            (string)$cfg['van_adres'],
            (string)$cfg['van_naam']
        );
    }

    $mailer = new SimpleMailer(
        (string)$cfg['smtp_host'],
        (int)$cfg['smtp_poort'],
        (string)$cfg['smtp_beveiliging'],
        (string)$cfg['smtp_gebruiker'],
        (string)$cfg['smtp_wachtwoord']
    );
    $mailer->fromAddr = (string)$cfg['van_adres'];
    $mailer->fromName = (string)$cfg['van_naam'];
    return $mailer;
}

function mail_geconfigureerd(): bool
{
    $cfg = mail_config();
    if (($cfg['van_adres'] ?? '') === '') {
        return false;
    }
    if (($cfg['methode'] ?? 'graph') === 'graph') {
        return $cfg['tenant_id'] !== '' && $cfg['client_id'] !== '' && $cfg['client_secret'] !== '';
    }
    return ($cfg['smtp_host'] ?? '') !== '';
}

// ─── Veilige headerwaarden ───────────────────────────────────────────────────

/**
 * Haalt alles uit een tekst wat een e-mailheader of een SMTP-commando kan
 * breken: regeleindes en nulbytes.
 *
 * Een adres of naam met een regeleinde erin laat de ontvanger van die tekst een
 * extra header of zelfs een extra SMTP-commando zien ("header injection"). De
 * adressen in dit portaal zijn allemaal gecontroleerd met geldig_email(), maar
 * deze functie is de laatste zeef vlak voor het protocol zelf — zodat één
 * vergeten controle elders nooit meteen een lek is.
 */
function mail_kopregel_veilig(string $waarde): string
{
    return trim(str_replace(["\r", "\n", "\0"], '', $waarde));
}

/** Adres dat veilig in MAIL FROM/RCPT TO en in een header mag; anders ''. */
function mail_adres_veilig(string $adres): string
{
    $adres = mail_kopregel_veilig($adres);
    return geldig_email($adres) ? $adres : '';
}

// ─── Versturen ───────────────────────────────────────────────────────────────

/**
 * Verstuurt een e-mail en legt het resultaat vast in mail_log.
 *
 * @param string $soort  inlogcode | uitnodiging | test
 * @param array  $fouten Wordt gevuld met foutmeldingen als het versturen mislukt.
 */
function verstuur_mail(
    string $naar,
    string $naarNaam,
    string $onderwerp,
    string $htmlBody,
    string $soort = 'inlogcode',
    array &$fouten = []
): bool {
    $fouten = [];
    $cfg    = mail_config();

    $naar      = mail_adres_veilig($naar);
    $naarNaam  = mail_kopregel_veilig($naarNaam);
    $onderwerp = mail_kopregel_veilig($onderwerp);

    if ($naar === '') {
        $fouten[] = 'Het ontvangeradres is geen geldig e-mailadres; er is niets verstuurd.';
        mail_loggen('(ongeldig adres)', $onderwerp, $soort, 'mislukt', (string)$cfg['methode'], $fouten[0]);
        return false;
    }

    if (!mail_geconfigureerd()) {
        $fouten[] = 'E-mail is nog niet ingesteld. Vul in Beheer > Instellingen de Graph-gegevens en het afzenderadres in.';
        mail_loggen($naar, $onderwerp, $soort, 'mislukt', (string)$cfg['methode'], implode(' | ', $fouten));
        return false;
    }

    $mailer = mailer_maken($cfg);

    try {
        $gelukt = $mailer->send($naar, $naarNaam, $onderwerp, $htmlBody);
    } catch (Throwable $e) {
        $gelukt = false;
        $mailer->errors[] = 'Onverwachte fout: ' . $e->getMessage();
    }

    if (!$gelukt) {
        $fouten = $mailer->errors;
        app_log('mail mislukt', ['naar' => $naar, 'soort' => $soort, 'fouten' => $fouten]);
    }

    mail_loggen(
        $naar,
        $onderwerp,
        $soort,
        $gelukt ? 'verzonden' : 'mislukt',
        (string)$cfg['methode'],
        $gelukt ? null : implode(' | ', $fouten)
    );

    return $gelukt;
}

function mail_loggen(string $ontvanger, string $onderwerp, string $soort, string $status, ?string $methode, ?string $fout): void
{
    try {
        db()->prepare('INSERT INTO mail_log (ontvanger, onderwerp, soort, status, methode, foutmelding)
                       VALUES (:o, :w, :s, :st, :m, :f)')
            ->execute([
                ':o'  => substr($ontvanger, 0, 190),
                ':w'  => substr($onderwerp, 0, 255),
                ':s'  => $soort,
                ':st' => $status,
                ':m'  => $methode,
                ':f'  => $fout,
            ]);
    } catch (Throwable $e) {
        app_log('mail_log mislukt', ['fout' => $e->getMessage()]);
    }
}

// ─── Sjablonen ───────────────────────────────────────────────────────────────

function mail_sjabloon_vullen(string $sjabloon, array $waarden): string
{
    $zoek = $vervang = [];
    foreach ($waarden as $sleutel => $waarde) {
        $zoek[]    = '{' . $sleutel . '}';
        $vervang[] = (string)$waarde;
    }
    return str_replace($zoek, $vervang, $sjabloon);
}

/** Zet letterlijke \n uit de instellingen om naar echte regeleindes. */
function mail_tekst_normaliseren(string $tekst): string
{
    return str_replace(['\r\n', '\n', '\r'], ["\n", "\n", "\n"], $tekst);
}

/** Bouwt een nette HTML-mail rond platte tekst. */
function mail_html_omhulsel(string $titel, string $platteTekst, string $extraHtml = ''): string
{
    $kleur = branding_kleur();
    $logo  = trim(instelling('branding_logo_url', ''));
    $naam  = portaal_naam();

    $alineas = '';
    foreach (preg_split('/\n{2,}/', trim($platteTekst)) as $alinea) {
        $alinea = trim($alinea);
        if ($alinea === '') {
            continue;
        }
        $alineas .= '<p style="margin:0 0 16px;line-height:1.6;color:#1f2937;font-size:15px;">'
            . nl2br(h($alinea)) . '</p>';
    }

    $logoHtml = $logo !== ''
        ? '<img src="' . h($logo) . '" alt="' . h($naam) . '" style="max-height:48px;max-width:220px;">'
        : '<span style="color:' . h(branding_tekstkleur($kleur))
            . ';font-size:20px;font-weight:600;">' . h($naam) . '</span>';

    return '<!DOCTYPE html><html lang="nl"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($titel) . '</title></head>'
        . '<body style="margin:0;padding:24px 12px;background:#f3f4f6;'
        . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;'
        . 'background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">'
        . '<tr><td style="background:' . h($kleur) . ';padding:24px;text-align:center;">' . $logoHtml . '</td></tr>'
        . '<tr><td style="padding:28px 28px 8px;">' . $alineas . $extraHtml . '</td></tr>'
        . '<tr><td style="padding:8px 28px 28px;">'
        . '<p style="margin:0;color:#6b7280;font-size:12px;line-height:1.5;">'
        . 'Deze e-mail is automatisch verstuurd door ' . h($naam) . '. U kunt er niet op antwoorden.'
        . '</p></td></tr></table></body></html>';
}

// ─── Concrete berichten ──────────────────────────────────────────────────────

function verstuur_inlogcode_mail(string $email, string $naam, string $code, int $minuten, array &$fouten = []): bool
{
    $waarden = [
        'naam'         => $naam !== '' ? $naam : 'deelnemer',
        'code'         => $code,
        'minuten'      => (string)$minuten,
        'portaal_naam' => portaal_naam(),
        'url'          => app_base_url(),
    ];

    $onderwerp = mail_sjabloon_vullen(
        instelling('mail_onderwerp_code', 'Uw inlogcode voor {portaal_naam}'),
        $waarden
    );
    $tekst = mail_sjabloon_vullen(
        mail_tekst_normaliseren(instelling(
            'mail_tekst_code',
            "Beste {naam},\n\nUw eenmalige inlogcode is: {code}\n\nDeze code is {minuten} minuten geldig.\nHeeft u geen code aangevraagd? Dan kunt u deze e-mail negeren."
        )),
        $waarden
    );

    // De code apart en groot, zodat hij makkelijk over te typen is.
    $codeBlok = '<div style="margin:8px 0 20px;padding:18px;background:#f9fafb;border:1px solid #e5e7eb;'
        . 'border-radius:10px;text-align:center;">'
        . '<div style="font-size:34px;letter-spacing:10px;font-weight:700;color:#111827;'
        . 'font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;">' . h($code) . '</div>'
        . '<div style="margin-top:8px;color:#6b7280;font-size:12px;">'
        . $minuten . ' minuten geldig · eenmalig te gebruiken</div></div>';

    // De code staat al in het tekstsjabloon; haal die regel eruit zodat hij niet dubbel staat.
    $tekstZonderCode = trim((string)preg_replace('/^.*' . preg_quote($code, '/') . '.*$/m', '', $tekst));

    $html = mail_html_omhulsel($onderwerp, $tekstZonderCode, $codeBlok);

    return verstuur_mail($email, $naam, $onderwerp, $html, 'inlogcode', $fouten);
}

function verstuur_uitnodiging_mail(string $email, string $naam, int $jaar, array &$fouten = []): bool
{
    $waarden = [
        'naam'         => $naam !== '' ? $naam : 'deelnemer',
        'jaar'         => (string)$jaar,
        'portaal_naam' => portaal_naam(),
        'url'          => app_base_url(),
    ];

    $onderwerp = mail_sjabloon_vullen(
        instelling('mail_onderwerp_uitnodiging', 'Uw video staat klaar — {portaal_naam}'),
        $waarden
    );
    $tekst = mail_sjabloon_vullen(
        mail_tekst_normaliseren(instelling(
            'mail_tekst_uitnodiging',
            "Beste {naam},\n\nDe videoregistratie van de musical van {jaar} staat voor u klaar.\n\nGa naar {url} en vul uw e-mailadres in. U ontvangt dan een eenmalige inlogcode waarmee u de video kunt downloaden."
        )),
        $waarden
    );

    $knop = '<div style="margin:8px 0 20px;text-align:center;">'
        . '<a href="' . h(app_base_url()) . '" style="display:inline-block;padding:12px 26px;'
        . 'background:' . h(branding_kleur())
        . ';color:' . h(branding_tekstkleur()) . ';text-decoration:none;'
        . 'border-radius:8px;font-weight:600;">Naar het portaal</a></div>';

    $html = mail_html_omhulsel($onderwerp, $tekst, $knop);

    return verstuur_mail($email, $naam, $onderwerp, $html, 'uitnodiging', $fouten);
}

function verstuur_testmail(string $email, array &$fouten = []): bool
{
    $onderwerp = 'Testbericht van ' . portaal_naam();
    $tekst = "Dit is een testbericht.\n\nAls u dit leest, werkt de e-mailconfiguratie van "
        . portaal_naam() . " naar behoren.\n\nVerstuurd op " . date('d-m-Y H:i') . '.';
    return verstuur_mail($email, '', $onderwerp, mail_html_omhulsel($onderwerp, $tekst), 'test', $fouten);
}

// ═══════════════════════════════════════════════════════════════════════════
//  Overgenomen mailerklassen (Klantenportaal) — dependency-vrij
// ═══════════════════════════════════════════════════════════════════════════

class SimpleMailer
{
    public string $fromAddr = '';
    public string $fromName = '';
    public array  $errors   = [];

    private string $host;
    private int    $port;
    private string $security;
    private string $user;
    private string $pass;

    public function __construct(string $host, int $port, string $security, string $user, string $pass)
    {
        $this->host     = $host;
        $this->port     = $port;
        $this->security = strtolower(trim($security));
        $this->user     = $user;
        $this->pass     = $pass;
    }

    public function send(string $to, string $toName, string $subject, string $htmlBody, string $cc = '', string $bcc = '', array $attachments = []): bool
    {
        if (empty($this->host)) {
            $this->errors[] = 'Geen SMTP host ingesteld.';
            return false;
        }

        // Laatste zeef vóór het SMTP-protocol: een adres met een regeleinde
        // erin zou hieronder een extra commando of een extra header worden.
        $to      = mail_adres_veilig($to);
        $cc      = $cc !== '' ? mail_adres_veilig($cc) : '';
        $bcc     = $bcc !== '' ? mail_adres_veilig($bcc) : '';
        $toName  = mail_kopregel_veilig($toName);
        $subject = mail_kopregel_veilig($subject);
        $this->fromAddr = mail_adres_veilig($this->fromAddr);
        $this->fromName = mail_kopregel_veilig($this->fromName);

        if ($to === '') {
            $this->errors[] = 'Het ontvangeradres is geen geldig e-mailadres.';
            return false;
        }
        if ($this->fromAddr === '') {
            $this->errors[] = 'Het afzenderadres is geen geldig e-mailadres.';
            return false;
        }

        $timeout = 15;
        $errno   = 0;
        $errstr  = '';

        if ($this->security === 'ssl') {
            $sock = @fsockopen("ssl://{$this->host}", $this->port, $errno, $errstr, $timeout);
        } else {
            $sock = @fsockopen($this->host, $this->port, $errno, $errstr, $timeout);
        }

        if (!$sock) {
            $this->errors[] = "Verbinding met SMTP mislukt ({$this->host}:{$this->port}): $errstr ($errno)";
            return false;
        }

        stream_set_timeout($sock, $timeout);

        try {
            $this->lees($sock); // 220 greeting

            $ehlo = gethostname() ?: 'localhost';
            $this->schrijf($sock, "EHLO $ehlo\r\n");
            $this->leesMulti($sock);

            // STARTTLS
            if ($this->security === 'tls') {
                $this->schrijf($sock, "STARTTLS\r\n");
                $resp = $this->lees($sock);
                if (!str_starts_with($resp, '220')) {
                    $this->errors[] = "STARTTLS geweigerd: $resp";
                    fclose($sock);
                    return false;
                }
                if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    $this->errors[] = 'TLS-verbinding kon niet worden opgezet.';
                    fclose($sock);
                    return false;
                }
                $this->schrijf($sock, "EHLO $ehlo\r\n");
                $this->leesMulti($sock);
            }

            // AUTH LOGIN
            if ($this->user !== '') {
                $this->schrijf($sock, "AUTH LOGIN\r\n");
                $this->lees($sock); // 334
                $this->schrijf($sock, base64_encode($this->user) . "\r\n");
                $this->lees($sock); // 334
                $this->schrijf($sock, base64_encode($this->pass) . "\r\n");
                $authResp = $this->lees($sock);
                if (!str_starts_with($authResp, '235')) {
                    $this->errors[] = "SMTP authenticatie mislukt: $authResp";
                    fclose($sock);
                    return false;
                }
            }

            // MAIL FROM
            $this->schrijf($sock, "MAIL FROM:<{$this->fromAddr}>\r\n");
            $fromResp = $this->lees($sock);
            if (!str_starts_with($fromResp, '250')) {
                $this->errors[] = "MAIL FROM geweigerd: $fromResp";
                fclose($sock);
                return false;
            }

            // RCPT TO – klant
            $this->schrijf($sock, "RCPT TO:<$to>\r\n");
            $rcptResp = $this->lees($sock);
            if (!str_starts_with($rcptResp, '250')) {
                $this->errors[] = "Ontvanger geweigerd ($to): $rcptResp";
                fclose($sock);
                return false;
            }

            // RCPT TO – CC bedrijf (optioneel)
            if ($cc !== '') {
                $this->schrijf($sock, "RCPT TO:<$cc>\r\n");
                $this->lees($sock);
            }

            // RCPT TO – BCC monteur (optioneel)
            if ($bcc !== '') {
                $this->schrijf($sock, "RCPT TO:<$bcc>\r\n");
                $this->lees($sock);
            }

            // DATA
            $this->schrijf($sock, "DATA\r\n");
            $this->lees($sock); // 354

            $bericht = $this->bouwBericht($to, $toName, $cc, $bcc, $subject, $htmlBody, $attachments);
            $this->schrijf($sock, $bericht . "\r\n.\r\n");
            $sendResp = $this->lees($sock);
            if (!str_starts_with($sendResp, '250')) {
                $this->errors[] = "Verzenden mislukt: $sendResp";
                fclose($sock);
                return false;
            }

            $this->schrijf($sock, "QUIT\r\n");
            fclose($sock);
            return true;
        } catch (\Throwable $e) {
            $this->errors[] = $e->getMessage();
            if (is_resource($sock)) fclose($sock);
            return false;
        }
    }

    private function bouwBericht(string $to, string $toName, string $cc, string $bcc, string $subject, string $htmlBody, array $attachments = []): string
    {
        $boundary = 'MP_' . md5(uniqid('', true));
        $altBoundary = 'ALT_' . md5(uniqid('', true));

        $encFrom    = '=?UTF-8?B?' . base64_encode($this->fromName) . '?=';
        $encTo      = '=?UTF-8?B?' . base64_encode($toName)         . '?=';
        $encSubject = '=?UTF-8?B?' . base64_encode($subject)         . '?=';

        $plain = strip_tags(str_replace(
            ['<br>', '<br/>', '<br />', '</p>', '</tr>', '</div>'],
            ["\n",   "\n",    "\n",     "\n\n",  "\n",    "\n"],
            $htmlBody
        ));
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace("/\n{3,}/", "\n\n", trim($plain));

        $headers  = "From: $encFrom <{$this->fromAddr}>\r\n";
        $headers .= "To: $encTo <$to>\r\n";
        if ($cc !== '') $headers .= "CC: $cc\r\n";
        if ($bcc !== '') $headers .= "BCC: $bcc\r\n";
        $headers .= "Subject: $encSubject\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: " . (empty($attachments)
            ? "multipart/alternative; boundary=\"$boundary\""
            : "multipart/mixed; boundary=\"$boundary\"") . "\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        $headers .= "X-Mailer: Werkbon-PHP\r\n";

        if (empty($attachments)) {
            $body  = "--$boundary\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode($plain)) . "\r\n";
            $body .= "--$boundary\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
            $body .= "--$boundary--";
            return $headers . "\r\n" . $body;
        }

        $body  = "--$boundary\r\n";
        $body .= "Content-Type: multipart/alternative; boundary=\"$altBoundary\"\r\n\r\n";
        $body .= "--$altBoundary\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($plain)) . "\r\n";
        $body .= "--$altBoundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        $body .= "--$altBoundary--\r\n";

        foreach ($attachments as $attachment) {
            $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)($attachment['filename'] ?? 'bijlage.pdf'));
            $mime = preg_replace('#[^A-Za-z0-9!#$&^_.+/-]+#', '', (string)($attachment['mime'] ?? 'application/octet-stream'));
            if ($mime === '') {
                $mime = 'application/octet-stream';
            }
            $content = (string)($attachment['content'] ?? '');
            $body .= "--$boundary\r\n";
            $body .= "Content-Type: $mime; name=\"$filename\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-Disposition: attachment; filename=\"$filename\"\r\n\r\n";
            $body .= chunk_split(base64_encode($content)) . "\r\n";
        }
        $body .= "--$boundary--";

        return $headers . "\r\n" . $body;
    }

    private function schrijf($sock, string $data): void
    {
        fwrite($sock, $data);
    }

    private function lees($sock): string
    {
        $resp = '';
        while (!feof($sock)) {
            $line  = fgets($sock, 512);
            $resp .= $line;
            // Einde van multi-line response: 4e karakter is spatie
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return trim($resp);
    }

    private function leesMulti($sock): string
    {
        return $this->lees($sock);
    }
}

// ─── Microsoft Graph API mailer ────────────────────────────────────────────

class GraphMailer
{
    public array $errors = [];

    private string $tenantId;
    private string $clientId;
    private string $clientSecret;
    private string $fromAddr;
    private string $fromName;

    public function __construct(
        string $tenantId,
        string $clientId,
        string $clientSecret,
        string $fromAddr,
        string $fromName
    ) {
        $this->tenantId     = $this->normalizeGraphValue($tenantId);
        $this->clientId     = $this->normalizeGraphValue($clientId);
        $this->clientSecret = $this->normalizeGraphSecret($clientSecret);
        $this->fromAddr     = trim($fromAddr);
        $this->fromName     = trim($fromName);
    }

    public function send(string $to, string $toName, string $subject, string $htmlBody, string $cc = '', string $bcc = '', array $attachments = []): bool
    {
        if (empty($this->tenantId) || empty($this->clientId) || empty($this->clientSecret)) {
            $this->errors[] = 'Graph API credentials zijn niet ingesteld (tenant_id, client_id, client_secret vereist).';
            return false;
        }
        if (empty($this->fromAddr)) {
            $this->errors[] = 'Afzenderadres is niet ingesteld.';
            return false;
        }

        $token = $this->getAccessToken();
        if ($token === null) {
            return false;
        }

        $message = [
            'subject' => $subject,
            'body'    => ['contentType' => 'HTML', 'content' => $htmlBody],
            'toRecipients' => [
                ['emailAddress' => ['address' => $to, 'name' => $toName]],
            ],
        ];
        if ($cc !== '') {
            $message['ccRecipients'] = [['emailAddress' => ['address' => $cc]]];
        }
        if ($bcc !== '') {
            $message['bccRecipients'] = [['emailAddress' => ['address' => $bcc]]];
        }
        if ($attachments !== []) {
            $message['attachments'] = array_map(static function (array $attachment): array {
                return [
                    '@odata.type' => '#microsoft.graph.fileAttachment',
                    'name' => (string)($attachment['filename'] ?? 'bijlage.pdf'),
                    'contentType' => (string)($attachment['mime'] ?? 'application/pdf'),
                    'contentBytes' => base64_encode((string)($attachment['content'] ?? '')),
                ];
            }, $attachments);
        }

        $payload = json_encode(['message' => $message, 'saveToSentItems' => false]);
        $url     = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($this->fromAddr) . '/sendMail';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
            ],
            CURLOPT_TIMEOUT        => 20,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->errors[] = 'cURL fout bij versturen: ' . $curlError;
            return false;
        }
        if ($httpCode === 202) {
            return true;
        }

        $body    = json_decode($response, true);
        $errCode = $body['error']['code'] ?? '';
        $errMsg  = $body['error']['message'] ?? $response;

        $prefix = "Graph API fout (HTTP $httpCode";
        if ($errCode !== '') {
            $prefix .= ", code $errCode";
        }
        $prefix .= "): ";
        $this->errors[] = $prefix . $errMsg;

        if ($httpCode === 403) {
            foreach ($this->buildForbiddenHints($token) as $hint) {
                $this->errors[] = $hint;
            }
        }

        return false;
    }

    public function diagnoseConfiguration(): array
    {
        $result = [
            'token_ok' => false,
            'roles' => [],
            'has_mail_send' => false,
            'mailbox_status' => 0,
            'mailbox_conclusief' => false,
            'mailbox_hint' => '',
            'errors' => [],
        ];

        if (empty($this->tenantId) || empty($this->clientId) || empty($this->clientSecret)) {
            $result['errors'][] = 'Graph API credentials ontbreken (tenant_id, client_id, client_secret).';
            return $result;
        }
        if (empty($this->fromAddr)) {
            $result['errors'][] = 'Afzenderadres ontbreekt.';
            return $result;
        }

        $token = $this->getAccessToken();
        if ($token === null) {
            $result['errors'] = $this->errors;
            return $result;
        }

        $result['token_ok'] = true;
        $result['roles'] = $this->extractTokenRoles($token);
        $result['has_mail_send'] = in_array('Mail.Send', $result['roles'], true);

        $probe = $this->probeMailboxAccess($token);
        $result['mailbox_status'] = (int)($probe['status'] ?? 0);

        if ($result['mailbox_status'] === 200) {
            $result['mailbox_conclusief'] = true;
            $result['mailbox_hint'] = 'Mailbox is bereikbaar via Graph.';
        } elseif ($this->isDirectoryPermissionDenial($probe, $result['roles'])) {
            $result['mailbox_hint'] = 'Niet te controleren: de app heeft alleen Mail.Send en mag de directory '
                . 'niet uitlezen. Dat is de bedoelde inrichting en geen fout — deze uitslag zegt niets over '
                . 'het verzenden. Gebruik de testmail om te controleren of mailen werkt.';
        } elseif ($result['mailbox_status'] === 404) {
            $result['mailbox_conclusief'] = true;
            $result['mailbox_hint'] = 'Mailbox niet gevonden in tenant of geen Exchange mailbox.';
        } elseif ($result['mailbox_status'] === 403) {
            $result['mailbox_conclusief'] = true;
            $result['mailbox_hint'] = 'Mailbox toegang geweigerd: mogelijk een Application Access Policy die deze '
                . 'postbus uitsluit, of ontbrekende rechten in Exchange Online.';
        } elseif (!empty($probe['error'])) {
            $result['mailbox_hint'] = 'Mailbox-check cURL fout: ' . (string)$probe['error'];
        }

        return $result;
    }

    private function getAccessToken(): ?string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $this->clientSecret)) {
            $this->errors[] = 'Client secret lijkt een Secret ID (GUID). Gebruik in Azure de Secret Value uit Certificates & secrets.';
            return null;
        }

        $url  = 'https://login.microsoftonline.com/' . rawurlencode($this->tenantId) . '/oauth2/v2.0/token';
        $body = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope'         => 'https://graph.microsoft.com/.default',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->errors[] = 'cURL fout bij token ophalen: ' . $curlError;
            return null;
        }

        $data = json_decode($response, true);
        if (isset($data['access_token'])) {
            return $data['access_token'];
        }

        $errMsg = $data['error_description'] ?? $data['error'] ?? $response;
        $this->errors[] = 'Token ophalen mislukt: ' . $errMsg;
        return null;
    }

    private function buildForbiddenHints(string $token): array
    {
        $hints = [];
        $roles = $this->extractTokenRoles($token);

        if ($roles === []) {
            $hints[] = '403 diagnose: token bevat geen app-rollen. Controleer Microsoft Graph API permissions + admin consent.';
        } elseif (!in_array('Mail.Send', $roles, true)) {
            $hints[] = '403 diagnose: app mist Graph applicatie-permissie Mail.Send (Application) of consent is niet verleend.';
        }

        $mailboxProbe = $this->probeMailboxAccess($token);
        if ($mailboxProbe['status'] === 404) {
            $hints[] = '403 diagnose: afzender-mailbox bestaat niet in deze tenant of heeft geen Exchange mailbox.';
        } elseif ($mailboxProbe['status'] === 403 && !$this->isDirectoryPermissionDenial($mailboxProbe, $roles)) {
            $hints[] = '403 diagnose: app heeft geen toegang tot deze mailbox (mogelijk Application Access Policy of ontbrekende rechten in Exchange Online).';
        }

        $hints[] = 'Checklist: verifieer Application permission Mail.Send, klik Grant admin consent, en gebruik als afzender een bestaande mailbox in dezelfde tenant.';

        return $hints;
    }

    private function extractTokenRoles(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return [];
        }

        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        if (!is_array($payload)) {
            return [];
        }

        $roles = $payload['roles'] ?? [];
        return is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
    }

    private function probeMailboxAccess(string $token): array
    {
        $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($this->fromAddr) . '?$select=id,mail,userPrincipalName';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['status' => 0, 'error' => $curlError, 'code' => ''];
        }

        return [
            'status'   => $httpCode,
            'response' => (string)$response,
            'code'     => $this->extractGraphErrorCode((string)$response),
        ];
    }

    /**
     * Haalt de foutcode uit een Graph-foutantwoord, bijvoorbeeld
     * "Authorization_RequestDenied" of "ErrorAccessDenied".
     */
    private function extractGraphErrorCode(string $response): string
    {
        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['error'])) {
            return '';
        }
        if (is_array($data['error']) && isset($data['error']['code']) && is_string($data['error']['code'])) {
            return $data['error']['code'];
        }
        return is_string($data['error']) ? $data['error'] : '';
    }

    /**
     * Een 403 op de directory-aanroep /users/{adres} betekent vrijwel altijd dat de app
     * alleen Mail.Send heeft en de directory niet mag uitlezen. Dat is precies de
     * inrichting die docs/GRAPH-SETUP.md voorschrijft: het is geen fout en het zegt niets
     * over het verzenden. Een Application Access Policy geldt namelijk voor
     * Exchange-resources, niet voor /users.
     */
    private function isDirectoryPermissionDenial(array $probe, array $roles): bool
    {
        if ((int)($probe['status'] ?? 0) !== 403) {
            return false;
        }

        $code = (string)($probe['code'] ?? '');
        if ($code !== '') {
            return stripos($code, 'Authorization_RequestDenied') !== false;
        }

        // Geen bruikbare foutcode: kijk of het token de directory überhaupt mag lezen.
        $leesrollen = [
            'User.Read.All',
            'User.ReadBasic.All',
            'User.ReadWrite.All',
            'Directory.Read.All',
            'Directory.ReadWrite.All',
        ];
        return array_intersect($leesrollen, $roles) === [];
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }
        return (string)base64_decode(strtr($value, '-_', '+/'));
    }

    private function normalizeGraphValue(string $value): string
    {
        $value = trim($value);
        $value = trim($value, "\"'");
        return preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value) ?? $value;
    }

    private function normalizeGraphSecret(string $value): string
    {
        $value = $this->normalizeGraphValue($value);
        $value = preg_replace('/\s+/u', '', $value) ?? $value;
        return $value;
    }
}
