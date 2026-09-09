<?php

/**
 * Toegangslogica: welke deelnemer mag welke jaargang en welk bestand.
 *
 * Eén plek voor de zichtbaarheidsregels van een jaargang, zodat het portaal,
 * de download en het beheer nooit uit elkaar kunnen lopen.
 */

if (!function_exists('db')) {
    require_once dirname(__DIR__) . '/config.php';
}

/** SQL-voorwaarde voor een jaargang die nu zichtbaar is voor deelnemers. */
function sql_jaargang_zichtbaar(string $alias = 'j'): string
{
    return "$alias.gepubliceerd = 1
            AND ($alias.zichtbaar_vanaf IS NULL OR $alias.zichtbaar_vanaf <= NOW())
            AND ($alias.verloopt_op IS NULL OR $alias.verloopt_op > NOW())";
}

function deelnemer_op_email(string $email): ?array
{
    $email = normaliseer_email($email);
    if ($email === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM deelnemers WHERE email = :e');
    $stmt->execute([':e' => $email]);
    return $stmt->fetch() ?: null;
}

/** Maakt de deelnemer aan als die nog niet bestaat en geeft de rij terug. */
function deelnemer_aanmaken_of_ophalen(string $email, ?string $naam = null): array
{
    $email = normaliseer_email($email);
    $bestaand = deelnemer_op_email($email);
    if ($bestaand) {
        if ($naam !== null && trim($naam) !== '' && trim((string)$bestaand['naam']) === '') {
            db()->prepare('UPDATE deelnemers SET naam = :n WHERE id = :id')
                ->execute([':n' => trim($naam), ':id' => (int)$bestaand['id']]);
            $bestaand['naam'] = trim($naam);
        }
        return $bestaand;
    }

    db()->prepare('INSERT INTO deelnemers (email, naam) VALUES (:e, :n)')
        ->execute([':e' => $email, ':n' => $naam !== null && trim($naam) !== '' ? trim($naam) : null]);

    return deelnemer_op_email($email) ?? ['id' => (int)db()->lastInsertId(), 'email' => $email, 'naam' => $naam];
}

/**
 * Heeft dit e-mailadres recht op ten minste één zichtbare jaargang?
 * Bepaalt of er überhaupt een inlogcode verstuurd wordt.
 */
function email_heeft_toegang(string $email): bool
{
    $email = normaliseer_email($email);
    if ($email === '') {
        return false;
    }
    $stmt = db()->prepare(
        'SELECT 1
         FROM deelnemers d
         JOIN toegang t   ON t.deelnemer_id = d.id
         JOIN jaargangen j ON j.id = t.jaargang_id
         WHERE d.email = :e AND d.geblokkeerd = 0 AND ' . sql_jaargang_zichtbaar('j') . '
         LIMIT 1'
    );
    $stmt->execute([':e' => $email]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Alle jaargangen waar deze deelnemer toegang toe heeft, elk met de bijbehorende
 * bestanden onder de sleutel 'bestanden'. Nieuwste jaar eerst.
 */
function deelnemer_jaargangen(int $deelnemerId): array
{
    $stmt = db()->prepare(
        'SELECT j.*
         FROM jaargangen j
         JOIN toegang t ON t.jaargang_id = j.id
         WHERE t.deelnemer_id = :d AND ' . sql_jaargang_zichtbaar('j') . '
         ORDER BY j.jaar DESC'
    );
    $stmt->execute([':d' => $deelnemerId]);
    $jaargangen = $stmt->fetchAll();
    if (!$jaargangen) {
        return [];
    }

    $ids = array_map(static fn(array $r): int => (int)$r['id'], $jaargangen);
    $plaatshouders = implode(',', array_fill(0, count($ids), '?'));

    $bStmt = db()->prepare(
        "SELECT * FROM jaargang_bestanden
         WHERE jaargang_id IN ($plaatshouders) AND actief = 1
         ORDER BY sortering ASC, id ASC"
    );
    $bStmt->execute($ids);

    $perJaargang = [];
    foreach ($bStmt->fetchAll() as $bestand) {
        $perJaargang[(int)$bestand['jaargang_id']][] = $bestand;
    }

    foreach ($jaargangen as &$jaargang) {
        $jaargang['bestanden'] = $perJaargang[(int)$jaargang['id']] ?? [];
    }
    unset($jaargang);

    return $jaargangen;
}

/**
 * Mag deze deelnemer dit bestand downloaden? Geeft de bestandsrij terug
 * (aangevuld met jaar en jaargang_titel) of null.
 */
function deelnemer_bestand(int $deelnemerId, int $bestandId): ?array
{
    $stmt = db()->prepare(
        'SELECT b.*, j.jaar, j.titel AS jaargang_titel
         FROM jaargang_bestanden b
         JOIN jaargangen j ON j.id = b.jaargang_id
         JOIN toegang t    ON t.jaargang_id = j.id
         JOIN deelnemers d ON d.id = t.deelnemer_id
         WHERE b.id = :b AND b.actief = 1
           AND t.deelnemer_id = :d AND d.geblokkeerd = 0
           AND ' . sql_jaargang_zichtbaar('j') . '
         LIMIT 1'
    );
    $stmt->execute([':b' => $bestandId, ':d' => $deelnemerId]);
    return $stmt->fetch() ?: null;
}

/** Aantal deelnemers met toegang tot een jaargang. */
function jaargang_aantal_deelnemers(int $jaargangId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM toegang WHERE jaargang_id = :j');
    $stmt->execute([':j' => $jaargangId]);
    return (int)$stmt->fetchColumn();
}

/** Koppelt een deelnemer aan een jaargang; bestaande koppeling blijft ongemoeid. */
function toegang_toekennen(int $deelnemerId, int $jaargangId, string $door = ''): bool
{
    $stmt = db()->prepare(
        'INSERT IGNORE INTO toegang (deelnemer_id, jaargang_id, toegevoegd_door)
         VALUES (:d, :j, :door)'
    );
    $stmt->execute([
        ':d'    => $deelnemerId,
        ':j'    => $jaargangId,
        ':door' => $door !== '' ? substr($door, 0, 150) : null,
    ]);
    return $stmt->rowCount() > 0;
}

function toegang_intrekken(int $deelnemerId, int $jaargangId): void
{
    db()->prepare('DELETE FROM toegang WHERE deelnemer_id = :d AND jaargang_id = :j')
        ->execute([':d' => $deelnemerId, ':j' => $jaargangId]);
}

/** Zichtbaarheidsstatus van een jaargang in tekst, voor het beheer. */
function jaargang_status(array $jaargang): array
{
    if ((int)$jaargang['gepubliceerd'] !== 1) {
        return ['concept', 'secondary'];
    }
    $nu = time();
    if (!empty($jaargang['zichtbaar_vanaf']) && strtotime((string)$jaargang['zichtbaar_vanaf']) > $nu) {
        return ['gepland', 'info'];
    }
    if (!empty($jaargang['verloopt_op']) && strtotime((string)$jaargang['verloopt_op']) <= $nu) {
        return ['verlopen', 'warning'];
    }
    return ['zichtbaar', 'success'];
}
