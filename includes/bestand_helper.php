<?php

/**
 * Bestanden in de opslagmap: namen, typen, koppelen aan een jaargang, en de
 * upload in delen via de browser.
 *
 * ── Upload in delen ─────────────────────────────────────────────────────────
 * Een videoregistratie van tientallen gigabytes past niet in één verzoek:
 * post_max_size, de webserver en een haperende thuisverbinding staan dat niet
 * toe. admin/assets/upload.js stuurt het bestand daarom in stukken naar
 * admin/upload.php:
 *
 *   1. start    — de browser meldt jaargang, naam, grootte en wijzigingsdatum.
 *                 Loopt er al een upload van precies dat bestand, dan krijgt
 *                 hij die terug, met het aantal bytes dat al binnen is.
 *   2. deel     — een stuk van het bestand, met de offset waar het begint.
 *                 Klopt die niet met wat er op schijf staat, dan weigert de
 *                 server en noemt hij de juiste offset. Een stuk wordt helemaal
 *                 of helemaal niet geschreven.
 *   3. afronden — alles is binnen: het bestand gaat naar de map van het jaar
 *                 en wordt desgewenst meteen gekoppeld.
 *
 * Tijdens het uploaden staat het bestand in <opslagmap>/.uploads/<sleutel>.deel.
 * bestand_scan_opslag() slaat alles met een punt ervoor over, dus een half
 * bestand is nooit te kiezen of te koppelen. Wat er op schijf staat is leidend
 * voor de voortgang; de tabel `uploads` onthoudt alleen wat bij welk bestand
 * hoort. De nachtelijke taak ruimt uploads op die een week stilliggen.
 */

if (!function_exists('db')) {
    require_once dirname(__DIR__) . '/config.php';
}

// ─── Toegestane bestandstypen ────────────────────────────────────────────────
const BESTAND_EXTENSIES = ['mp4', 'mkv', 'mov', 'm4v', 'webm', 'avi', 'zip'];
const BESTAND_MAX_DIEPTE = 3;

// ─── Upload in delen ─────────────────────────────────────────────────────────
const UPLOAD_MAP = '.uploads';
const UPLOAD_DEEL_MAX = 32 * 1024 * 1024;
const UPLOAD_DEEL_MIN = 64 * 1024;
const UPLOAD_VERLOOP_DAGEN = 7;
/** Ruimte die na een upload nog vrij moet blijven op de schijf. */
const UPLOAD_SCHIJF_MARGE = 64 * 1024 * 1024;

/**
 * Fout die admin/upload.php als JSON teruggeeft. De HTTP-status zegt de browser
 * wat hij moet doen (opnieuw proberen, verder vanaf een andere offset, stoppen);
 * $extra gaat mee in het antwoord.
 */
final class UploadFout extends RuntimeException
{
    public function __construct(public readonly int $status, string $melding, public readonly array $extra = [])
    {
        parent::__construct($melding);
    }
}

/** MIME-type op basis van de extensie; nooit op basis van gebruikersinvoer. */
function mime_uit_extensie(string $extensie): string
{
    return [
        'mp4'  => 'video/mp4',
        'm4v'  => 'video/x-m4v',
        'mkv'  => 'video/x-matroska',
        'mov'  => 'video/quicktime',
        'webm' => 'video/webm',
        'avi'  => 'video/x-msvideo',
        'zip'  => 'application/zip',
    ][strtolower($extensie)] ?? 'application/octet-stream';
}

/**
 * Naam die de bezoeker in zijn downloadmap ziet. Ruimer dan de naam op schijf:
 * spaties en accenten mogen hier wél. Padscheidingstekens, aanhalingstekens,
 * puntkomma's en regeleindes gaan eruit — die kunnen de Content-Disposition-
 * header breken.
 */
function download_veilige_naam(string $naam): string
{
    $naam = str_replace(['\\', '/'], ' ', $naam);
    $naam = preg_replace('/[\x00-\x1F\x7F";]+/u', '', $naam) ?? '';
    $naam = preg_replace('/\s+/u', ' ', $naam) ?? '';
    $naam = trim($naam, " ._-");
    return $naam === '' ? 'bestand' : mb_substr($naam, 0, 200);
}

/** Maakt een bestandsnaam veilig: alleen letters, cijfers, punt, streepje, underscore. */
function bestand_veilige_naam(string $naam): string
{
    $naam = basename(str_replace('\\', '/', $naam));
    $naam = preg_replace('/[^A-Za-z0-9._-]+/', '_', $naam) ?? '';
    $naam = trim($naam, '._-');
    return $naam === '' ? 'bestand' : substr($naam, 0, 200);
}

/**
 * Een naam in $map die nog niet bestaat: $naam zelf, of anders naam-2.mp4,
 * naam-3.mp4, … Bestaande bestanden worden nooit overschreven.
 */
function bestand_vrije_naam(string $map, string $naam): string
{
    $extensie  = strtolower((string)pathinfo($naam, PATHINFO_EXTENSION));
    $basisNaam = (string)pathinfo($naam, PATHINFO_FILENAME);
    $teller    = 1;
    while (file_exists($map . '/' . $naam)) {
        $teller++;
        $naam = $basisNaam . '-' . $teller . '.' . $extensie;
    }
    return $naam;
}

/**
 * Scant de opslagmap recursief (maximaal BESTAND_MAX_DIEPTE niveaus) op
 * videobestanden. Geeft een lijst met relatief pad => [pad, bytes, ext].
 */
function bestand_scan_opslag(): array
{
    $basis = realpath(opslag_pad());
    if ($basis === false || !is_dir($basis)) {
        return [];
    }

    $gevonden = [];
    $stapel   = [['', 1]];

    while ($stapel) {
        [$relatieveMap, $diepte] = array_pop($stapel);
        $map   = $relatieveMap === '' ? $basis : $basis . '/' . $relatieveMap;
        $items = @scandir($map);
        if ($items === false) {
            continue;
        }
        foreach ($items as $item) {
            // Ook .uploads: daar staan halve bestanden.
            if ($item === '.' || $item === '..' || $item[0] === '.') {
                continue;
            }
            $volledig = $map . '/' . $item;
            if (is_link($volledig)) {
                continue;   // symlinks nooit volgen: die kunnen buiten de opslagmap wijzen
            }
            $relatief = $relatieveMap === '' ? $item : $relatieveMap . '/' . $item;

            if (is_dir($volledig)) {
                if ($diepte < BESTAND_MAX_DIEPTE) {
                    $stapel[] = [$relatief, $diepte + 1];
                }
                continue;
            }
            if (!is_file($volledig)) {
                continue;
            }
            $ext = strtolower((string)pathinfo($item, PATHINFO_EXTENSION));
            if (!in_array($ext, BESTAND_EXTENSIES, true)) {
                continue;
            }
            $gevonden[$relatief] = [
                'pad'   => $relatief,
                'bytes' => (int)@filesize($volledig),
                'ext'   => $ext,
            ];
        }
    }

    ksort($gevonden, SORT_NATURAL | SORT_FLAG_CASE);
    return $gevonden;
}

/** Alle paden die al aan een jaargang gekoppeld zijn: pad => jaar. */
function bestand_gekoppelde_paden(): array
{
    try {
        $rijen = db()->query(
            'SELECT b.pad, j.jaar FROM jaargang_bestanden b
               JOIN jaargangen j ON j.id = b.jaargang_id'
        )->fetchAll() ?: [];
    } catch (Throwable $e) {
        app_log('gekoppelde paden ophalen mislukt', ['fout' => $e->getMessage()]);
        return [];
    }
    $kaart = [];
    foreach ($rijen as $rij) {
        $kaart[(string)$rij['pad']] = (int)$rij['jaar'];
    }
    return $kaart;
}

/**
 * Koppelt een bestand uit de opslagmap aan een jaargang.
 *
 * $gegevens: titel, bestandsnaam (de downloadnaam), sortering, actief. Zonder
 * titel wordt het "Videoregistratie <jaar>", zonder downloadnaam de naam op
 * schijf. Gooit een exception als de jaargang niet bestaat of het opslaan
 * mislukt.
 */
function bestand_koppelen(int $jaargangId, string $relatief, int $bytes, array $gegevens): void
{
    $stmt = db()->prepare('SELECT jaar FROM jaargangen WHERE id = :id');
    $stmt->execute([':id' => $jaargangId]);
    $jaar = $stmt->fetchColumn();
    if ($jaar === false) {
        throw new RuntimeException('Jaargang ' . $jaargangId . ' bestaat niet.');
    }

    $titel = trim((string)($gegevens['titel'] ?? ''));
    if ($titel === '') {
        $titel = 'Videoregistratie ' . (int)$jaar;
    }
    $naam = trim((string)($gegevens['bestandsnaam'] ?? ''));

    db()->prepare(
        'INSERT INTO jaargang_bestanden
            (jaargang_id, titel, bestandsnaam, pad, bytes, mime, sortering, actief)
         VALUES (:j, :t, :n, :p, :b, :m, :s, :a)'
    )->execute([
        ':j' => $jaargangId,
        ':t' => mb_substr($titel, 0, 150),
        ':n' => mb_substr(download_veilige_naam($naam !== '' ? $naam : basename($relatief)), 0, 255),
        ':p' => substr($relatief, 0, 500),
        ':b' => $bytes,
        ':m' => mime_uit_extensie((string)pathinfo($relatief, PATHINFO_EXTENSION)),
        ':s' => (int)($gegevens['sortering'] ?? 0),
        ':a' => !empty($gegevens['actief']) ? 1 : 0,
    ]);
}

// ─── Upload in delen ─────────────────────────────────────────────────────────

/** Een php.ini-waarde als "8M" of "2G" in bytes. 0 betekent: geen limiet. */
function ini_bytes(string $waarde): int
{
    $waarde = trim($waarde);
    if ($waarde === '') {
        return 0;
    }
    $getal = (int)$waarde;
    return match (strtolower(substr($waarde, -1))) {
        'g'     => $getal * 1024 * 1024 * 1024,
        'm'     => $getal * 1024 * 1024,
        'k'     => $getal * 1024,
        default => $getal,
    };
}

/**
 * Hoe groot één stuk mag zijn. Ook een ruwe body telt helemaal mee voor
 * post_max_size; is hij groter, dan gooit PHP hem ongezien weg. Een webserver
 * met een lagere limiet (nginx: client_max_body_size) antwoordt met 413; de
 * browser halveert de stukken dan zelf.
 */
function upload_deelgrootte(): int
{
    $grootte = UPLOAD_DEEL_MAX;
    $postMax = ini_bytes((string)ini_get('post_max_size'));
    if ($postMax > 0) {
        $grootte = min($grootte, $postMax - 64 * 1024);
    }
    return max(UPLOAD_DEEL_MIN, $grootte);
}

/**
 * Maakt de tabel `uploads` aan als die er nog niet is: db.sql draait alleen bij
 * de installatie. Houd deze definitie gelijk aan die in db.sql.
 */
function upload_tabel(): void
{
    static $aanwezig = false;
    if ($aanwezig || tabel_bestaat('uploads')) {
        $aanwezig = true;
        return;
    }
    db()->exec(
        'CREATE TABLE IF NOT EXISTS uploads (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            sleutel       CHAR(32) NOT NULL,
            vingerafdruk  CHAR(64) NOT NULL,
            jaargang_id   INT UNSIGNED NOT NULL,
            beheerder_id  INT UNSIGNED NULL,
            origineel     VARCHAR(255) NOT NULL,
            naam          VARCHAR(255) NOT NULL,
            bytes         BIGINT UNSIGNED NOT NULL,
            aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            bijgewerkt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_sleutel (sleutel),
            UNIQUE KEY uniq_vingerafdruk (vingerafdruk),
            KEY idx_jaargang (jaargang_id),
            KEY idx_bijgewerkt (bijgewerkt_op),
            CONSTRAINT fk_upload_jaargang FOREIGN KEY (jaargang_id)
                REFERENCES jaargangen (id) ON DELETE CASCADE,
            CONSTRAINT fk_upload_beheerder FOREIGN KEY (beheerder_id)
                REFERENCES beheerders (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $aanwezig = true;
}

/** De map met halve bestanden; wordt zo nodig aangemaakt. */
function upload_werkmap(): string
{
    $map = opslag_pad() . '/' . UPLOAD_MAP;
    if (!is_dir($map) && !@mkdir($map, 0775, true) && !is_dir($map)) {
        throw new UploadFout(500, 'De server kan niet in de opslagmap schrijven. '
            . 'Vraag de technisch beheerder de webserver schrijfrechten te geven op ' . opslag_pad() . '.');
    }
    return $map;
}

/** Pad van het halve bestand. De sleutel is altijd 32 hexadecimale tekens. */
function upload_deelpad(string $sleutel): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $sleutel)) {
        throw new UploadFout(404, 'Deze upload bestaat niet.');
    }
    return opslag_pad() . '/' . UPLOAD_MAP . '/' . $sleutel . '.deel';
}

function upload_ophalen(string $sleutel): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $sleutel) || !tabel_bestaat('uploads')) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM uploads WHERE sleutel = :s');
    $stmt->execute([':s' => $sleutel]);
    return $stmt->fetch() ?: null;
}

/** Hoeveel bytes er van deze upload binnen zijn. */
function upload_ontvangen(array $upload): int
{
    $pad = opslag_pad() . '/' . UPLOAD_MAP . '/' . $upload['sleutel'] . '.deel';
    clearstatcache(true, $pad);
    return is_file($pad) ? (int)filesize($pad) : 0;
}

/**
 * Begint een upload, of zoekt de lopende upload van hetzelfde bestand op.
 * Hetzelfde bestand: zelfde jaargang, naam, grootte en wijzigingsdatum.
 */
function upload_start(int $beheerderId, int $jaargangId, string $origineel, int $bytes, int $gewijzigd): array
{
    upload_tabel();

    $stmt = db()->prepare('SELECT jaar FROM jaargangen WHERE id = :id');
    $stmt->execute([':id' => $jaargangId]);
    $jaar = $stmt->fetchColumn();
    if ($jaar === false) {
        throw new UploadFout(404, 'Deze jaargang bestaat niet (meer). Ververs de pagina.');
    }
    $jaar = (int)$jaar;

    $origineel = trim(basename(str_replace('\\', '/', $origineel)));
    $extensie  = strtolower((string)pathinfo($origineel, PATHINFO_EXTENSION));
    if (!in_array($extensie, BESTAND_EXTENSIES, true)) {
        throw new UploadFout(415, 'Dit bestandstype is niet toegestaan. Toegestaan: '
            . implode(', ', BESTAND_EXTENSIES) . '.');
    }
    if ($bytes <= 0) {
        throw new UploadFout(422, 'Het bestand is leeg.');
    }
    $naam = bestand_veilige_naam($origineel);
    if (strtolower((string)pathinfo($naam, PATHINFO_EXTENSION)) !== $extensie) {
        $naam .= '.' . $extensie;
    }

    $werkmap      = upload_werkmap();
    $vingerafdruk = hash('sha256', $jaargangId . "\0" . $origineel . "\0" . $bytes . "\0" . $gewijzigd);

    $zoek = db()->prepare('SELECT * FROM uploads WHERE vingerafdruk = :v');
    $zoek->execute([':v' => $vingerafdruk]);
    $upload = $zoek->fetch() ?: null;

    if ($upload === null) {
        try {
            db()->prepare(
                'INSERT INTO uploads (sleutel, vingerafdruk, jaargang_id, beheerder_id, origineel, naam, bytes)
                 VALUES (:s, :v, :j, :b, :o, :n, :g)'
            )->execute([
                ':s' => bin2hex(random_bytes(16)),
                ':v' => $vingerafdruk,
                ':j' => $jaargangId,
                ':b' => $beheerderId,
                ':o' => mb_substr($origineel, 0, 255),
                ':n' => $naam,
                ':g' => $bytes,
            ]);
        } catch (PDOException $e) {
            // Twee vensters die tegelijk beginnen: de ander was net eerder.
            if ((string)$e->getCode() !== '23000') {
                throw $e;
            }
        }
        $zoek->execute([':v' => $vingerafdruk]);
        $upload = $zoek->fetch();
    }

    $deelpad = upload_deelpad((string)$upload['sleutel']);
    if (!is_file($deelpad) && @file_put_contents($deelpad, '') === false) {
        throw new UploadFout(500, 'De server kan niet in de opslagmap schrijven.');
    }
    $ontvangen = upload_ontvangen($upload);
    if ($ontvangen > $bytes) {
        // Kan alleen als het halve bestand van buitenaf is aangepast.
        file_put_contents($deelpad, '');
        $ontvangen = 0;
    }

    $nodig = $bytes - $ontvangen;
    $vrij  = @disk_free_space($werkmap);
    if ($vrij !== false && $vrij < $nodig + UPLOAD_SCHIJF_MARGE) {
        throw new UploadFout(507, 'Er is niet genoeg ruimte op de server: nodig ' . formatteer_bytes($nodig)
            . ', vrij ' . formatteer_bytes((int)$vrij) . '. Ruim oude bestanden op of vraag de technisch beheerder om meer ruimte.');
    }

    $doel = opslag_pad() . '/' . $jaar . '/' . $naam;
    return [
        'sleutel'     => (string)$upload['sleutel'],
        'ontvangen'   => $ontvangen,
        'bytes'       => $bytes,
        'deelgrootte' => upload_deelgrootte(),
        'doel'        => $jaar . '/' . $naam,
        'bestaat_al'  => is_file($doel) && (int)@filesize($doel) === $bytes,
    ];
}

/**
 * Opent het halve bestand met een exclusieve vergrendeling, zodat twee
 * vensters nooit tegelijk in hetzelfde bestand schrijven.
 *
 * @return resource
 */
function upload_vergrendel(array $upload)
{
    $pad = upload_deelpad((string)$upload['sleutel']);
    $fh  = @fopen($pad, 'r+b');
    if ($fh === false) {
        // Weggehaald (geannuleerd in een ander venster, of opgeruimd): de
        // browser begint dan opnieuw met start.
        throw new UploadFout(410, 'Het tijdelijke bestand van deze upload bestaat niet meer.');
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        throw new UploadFout(423, 'Dit bestand wordt al in een ander venster of op een andere computer geüpload.');
    }
    return $fh;
}

/**
 * Schrijft één stuk uit php://input achter het halve bestand en geeft het
 * nieuwe aantal ontvangen bytes terug. Komt het stuk niet helemaal aan, of
 * klopt de meegestuurde SHA-256 niet, dan wordt het bestand teruggezet op de
 * lengte van vóór dit stuk.
 */
function upload_deel_schrijven(array $upload, int $offset, int $lengte, ?string $sha256): int
{
    $totaal = (int)$upload['bytes'];
    if ($offset < 0 || $lengte <= 0 || $offset + $lengte > $totaal) {
        throw new UploadFout(400, 'Dit stuk valt buiten het bestand.', ['ontvangen' => upload_ontvangen($upload)]);
    }

    $fh = upload_vergrendel($upload);
    try {
        $huidig = (int)fstat($fh)['size'];
        if ($huidig !== $offset) {
            throw new UploadFout(409, 'De server had al ' . formatteer_bytes($huidig) . ' ontvangen.', ['ontvangen' => $huidig]);
        }

        $invoer = fopen('php://input', 'rb');
        $hash   = hash_init('sha256');
        $geschreven = 0;
        fseek($fh, $offset);
        while ($geschreven < $lengte && !feof($invoer)) {
            $blok = fread($invoer, min(1024 * 1024, $lengte - $geschreven));
            if ($blok === false || $blok === '') {
                break;
            }
            hash_update($hash, $blok);
            if (fwrite($fh, $blok) !== strlen($blok)) {
                ftruncate($fh, $offset);
                throw new UploadFout(507, 'De server kon niet verder schrijven. Is de schijf vol?');
            }
            $geschreven += strlen($blok);
        }
        fclose($invoer);

        if ($geschreven !== $lengte) {
            ftruncate($fh, $offset);
            throw new UploadFout(400, 'Dit stuk is niet volledig aangekomen.', ['ontvangen' => $offset]);
        }
        if ($sha256 !== null && $sha256 !== '' && !hash_equals(strtolower($sha256), hash_final($hash))) {
            ftruncate($fh, $offset);
            throw new UploadFout(422, 'Dit stuk is onderweg beschadigd.', ['ontvangen' => $offset]);
        }
        fflush($fh);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    db()->prepare('UPDATE uploads SET bijgewerkt_op = NOW() WHERE id = :id')->execute([':id' => (int)$upload['id']]);
    return $offset + $lengte;
}

/**
 * Zet een complete upload in de map van het jaar en koppelt hem desgewenst.
 * $koppeling: null, of de gegevens voor bestand_koppelen().
 *
 * @return array{pad: string, bytes: int, gekoppeld: bool, koppelen_mislukt: bool}
 */
function upload_afronden(array $upload, ?array $koppeling): array
{
    $fh = upload_vergrendel($upload);
    try {
        $ontvangen = (int)fstat($fh)['size'];
        if ($ontvangen !== (int)$upload['bytes']) {
            throw new UploadFout(409, 'De upload is nog niet compleet.', ['ontvangen' => $ontvangen]);
        }

        $stmt = db()->prepare('SELECT jaar FROM jaargangen WHERE id = :id');
        $stmt->execute([':id' => (int)$upload['jaargang_id']]);
        $jaar = $stmt->fetchColumn();
        if ($jaar === false) {
            throw new UploadFout(404, 'De jaargang van deze upload bestaat niet meer.');
        }

        $map = opslag_pad() . '/' . (int)$jaar;
        if (!is_dir($map) && !@mkdir($map, 0775, true) && !is_dir($map)) {
            throw new UploadFout(500, 'De map ' . (int)$jaar . ' kon niet in de opslagmap worden aangemaakt.');
        }
        $naam = bestand_vrije_naam($map, (string)$upload['naam']);
        if (!@rename(upload_deelpad((string)$upload['sleutel']), $map . '/' . $naam)) {
            throw new UploadFout(500, 'Het bestand kon niet naar de map ' . (int)$jaar . ' worden verplaatst.');
        }
        @chmod($map . '/' . $naam, 0644);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    db()->prepare('DELETE FROM uploads WHERE id = :id')->execute([':id' => (int)$upload['id']]);

    $relatief  = (int)$jaar . '/' . $naam;
    $gekoppeld = false;
    if ($koppeling !== null) {
        try {
            bestand_koppelen((int)$upload['jaargang_id'], $relatief, $ontvangen, $koppeling);
            $gekoppeld = true;
        } catch (Throwable $e) {
            app_log('geüpload bestand koppelen mislukt', ['pad' => $relatief, 'fout' => $e->getMessage()]);
        }
    }

    return [
        'pad'              => $relatief,
        'bytes'            => $ontvangen,
        'gekoppeld'        => $gekoppeld,
        'koppelen_mislukt' => $koppeling !== null && !$gekoppeld,
    ];
}

/** Breekt een upload af: het halve bestand en de regel verdwijnen. */
function upload_verwijderen(array $upload): void
{
    $pad = upload_deelpad((string)$upload['sleutel']);
    $fh  = @fopen($pad, 'r+b');
    if ($fh !== false) {
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            throw new UploadFout(423, 'Deze upload is op dit moment bezig. Pauzeer of annuleer hem in het venster waarin hij loopt.');
        }
        @unlink($pad);
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    db()->prepare('DELETE FROM uploads WHERE id = :id')->execute([':id' => (int)$upload['id']]);
}

/** Onafgemaakte uploads van een jaargang, met de voortgang en wie begon. */
function upload_openstaand(int $jaargangId): array
{
    if (!tabel_bestaat('uploads')) {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT u.*, b.naam AS beheerder_naam
           FROM uploads u
           LEFT JOIN beheerders b ON b.id = u.beheerder_id
          WHERE u.jaargang_id = :j
          ORDER BY u.bijgewerkt_op DESC'
    );
    $stmt->execute([':j' => $jaargangId]);
    $rijen = $stmt->fetchAll() ?: [];
    foreach ($rijen as &$rij) {
        $rij['ontvangen'] = upload_ontvangen($rij);
    }
    return $rijen;
}

/**
 * Voor de nachtelijke taak: uploads die $dagen niets meer ontvingen, en halve
 * bestanden waar geen regel meer bij hoort (bijvoorbeeld na het verwijderen van
 * een jaargang). Geeft het aantal verwijderde uploads terug.
 */
function upload_opruimen(int $dagen): int
{
    if (!tabel_bestaat('uploads')) {
        return 0;
    }
    $aantal = 0;
    $oud = db()->query(sprintf(
        'SELECT * FROM uploads WHERE bijgewerkt_op < (NOW() - INTERVAL %d DAY)',
        max(1, $dagen)
    ))->fetchAll() ?: [];
    foreach ($oud as $upload) {
        try {
            upload_verwijderen($upload);
            $aantal++;
        } catch (UploadFout $e) {
            // Toch nog bezig: laten staan.
        }
    }

    $bekend = array_flip(db()->query('SELECT sleutel FROM uploads')->fetchAll(PDO::FETCH_COLUMN) ?: []);
    foreach (glob(opslag_pad() . '/' . UPLOAD_MAP . '/*.deel') ?: [] as $pad) {
        // Een dag wachten: een net begonnen upload heeft zijn regel misschien nog niet.
        if (!isset($bekend[basename($pad, '.deel')]) && (int)@filemtime($pad) < time() - 86400) {
            @unlink($pad) && $aantal++;
        }
    }
    return $aantal;
}
