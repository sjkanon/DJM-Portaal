-- ═══════════════════════════════════════════════════════════════════════════
--  DJM Portaal — Deventer Jeugd Musical — databaseschema
--  MySQL 5.7+ / MariaDB 10.3+  ·  utf8mb4
--  Uitvoeren via setup.php of: mysql -u user -p djm_portaal < db.sql
-- ═══════════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ─── Jaargangen ────────────────────────────────────────────────────────────
-- Eén rij per jaar. Een jaar toevoegen = één rij hier + bestanden + toegang.
CREATE TABLE IF NOT EXISTS jaargangen (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    jaar            SMALLINT UNSIGNED NOT NULL,
    titel           VARCHAR(150) NOT NULL,
    slug            VARCHAR(80) NOT NULL,
    omschrijving    TEXT NULL,
    gepubliceerd    TINYINT(1) NOT NULL DEFAULT 0,
    zichtbaar_vanaf DATETIME NULL,
    verloopt_op     DATETIME NULL,
    aangemaakt_op   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    gewijzigd_op    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_jaar (jaar),
    UNIQUE KEY uniq_slug (slug),
    KEY idx_gepubliceerd (gepubliceerd, jaar)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Bestanden per jaargang ────────────────────────────────────────────────
-- 'pad' is ALTIJD relatief t.o.v. OPSLAG_PAD en bevat nooit '..'.
CREATE TABLE IF NOT EXISTS jaargang_bestanden (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    jaargang_id   INT UNSIGNED NOT NULL,
    titel         VARCHAR(150) NOT NULL,
    bestandsnaam  VARCHAR(255) NOT NULL,           -- naam die de bezoeker downloadt
    pad           VARCHAR(500) NOT NULL,           -- relatief pad binnen OPSLAG_PAD
    bytes         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    mime          VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream',
    sha256        CHAR(64) NULL,
    sortering     SMALLINT NOT NULL DEFAULT 0,
    actief        TINYINT(1) NOT NULL DEFAULT 1,
    aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_jaargang (jaargang_id, actief, sortering),
    CONSTRAINT fk_bestand_jaargang FOREIGN KEY (jaargang_id)
        REFERENCES jaargangen (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Deelnemers (identiteit = e-mailadres) ─────────────────────────────────
CREATE TABLE IF NOT EXISTS deelnemers (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email             VARCHAR(190) NOT NULL,        -- genormaliseerd: trim + lowercase
    naam              VARCHAR(150) NULL,
    geblokkeerd       TINYINT(1) NOT NULL DEFAULT 0,
    aangemaakt_op     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    laatst_ingelogd_op DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Toegang: welke deelnemer mag welke jaargang ───────────────────────────
CREATE TABLE IF NOT EXISTS toegang (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    deelnemer_id   INT UNSIGNED NOT NULL,
    jaargang_id    INT UNSIGNED NOT NULL,
    toegevoegd_op  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    toegevoegd_door VARCHAR(150) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_toegang (deelnemer_id, jaargang_id),
    KEY idx_jaargang (jaargang_id),
    CONSTRAINT fk_toegang_deelnemer FOREIGN KEY (deelnemer_id)
        REFERENCES deelnemers (id) ON DELETE CASCADE,
    CONSTRAINT fk_toegang_jaargang FOREIGN KEY (jaargang_id)
        REFERENCES jaargangen (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Eenmalige inlogcodes ──────────────────────────────────────────────────
-- code_hash = hash_hmac('sha256', code, OTP_PEPPER). De code zelf wordt nooit opgeslagen.
CREATE TABLE IF NOT EXISTS login_codes (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email         VARCHAR(190) NOT NULL,
    deelnemer_id  INT UNSIGNED NULL,
    code_hash     CHAR(64) NOT NULL,
    challenge_id  CHAR(32) NOT NULL,               -- bindt de code aan de browsersessie
    pogingen      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    verloopt_op   DATETIME NOT NULL,
    gebruikt_op   DATETIME NULL,
    ingetrokken_op DATETIME NULL,
    ip            VARBINARY(16) NULL,
    aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_email_actief (email, gebruikt_op, ingetrokken_op),
    KEY idx_challenge (challenge_id),
    KEY idx_verloopt (verloopt_op)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Onthoud-dit-apparaat tokens ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS remember_tokens (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    deelnemer_id   INT UNSIGNED NOT NULL,
    selector       CHAR(24) NOT NULL,
    token_hash     CHAR(64) NOT NULL,
    verloopt_op    DATETIME NOT NULL,
    laatst_gebruikt DATETIME NULL,
    ip             VARBINARY(16) NULL,
    aangemaakt_op  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_selector (selector),
    KEY idx_deelnemer (deelnemer_id),
    CONSTRAINT fk_remember_deelnemer FOREIGN KEY (deelnemer_id)
        REFERENCES deelnemers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Rate limiting (codeaanvraag én verificatie) ───────────────────────────
CREATE TABLE IF NOT EXISTS aanvraag_limiet (
    sleutel      VARCHAR(190) NOT NULL,            -- bv. 'code:jan@example.nl' of 'ip:1.2.3.4'
    teller       INT UNSIGNED NOT NULL DEFAULT 0,
    venster_start DATETIME NOT NULL,
    PRIMARY KEY (sleutel),
    KEY idx_venster (venster_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Downloadlogboek ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS download_log (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    deelnemer_id   INT UNSIGNED NULL,
    bestand_id     INT UNSIGNED NULL,
    jaargang_id    INT UNSIGNED NULL,
    email          VARCHAR(190) NULL,              -- snapshot, blijft na verwijderen deelnemer
    bestandsnaam   VARCHAR(255) NULL,
    ip             VARBINARY(16) NULL,
    user_agent     VARCHAR(255) NULL,
    methode        VARCHAR(20) NULL,               -- xaccel | xsendfile | php
    bytes_verzonden BIGINT UNSIGNED NOT NULL DEFAULT 0,
    afgerond       TINYINT(1) NOT NULL DEFAULT 0,
    -- Waaróm hij stopte: bezig | voltooid | client_gestopt | server_gestopt |
    -- webserver. Alleen de PHP-uitlevering kan dit vaststellen; bij X-Accel en
    -- X-Sendfile levert de webserver uit en weten wij het niet ('webserver').
    reden          VARCHAR(24) NULL,
    gestart_op     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_deelnemer (deelnemer_id),
    KEY idx_gestart (gestart_op)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Mailboek ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS mail_log (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ontvanger     VARCHAR(190) NOT NULL,
    onderwerp     VARCHAR(255) NOT NULL,
    soort         VARCHAR(40) NOT NULL DEFAULT 'inlogcode',  -- inlogcode | uitnodiging | test
    status        VARCHAR(20) NOT NULL DEFAULT 'verzonden',  -- verzonden | mislukt
    methode       VARCHAR(20) NULL,                          -- graph | smtp
    foutmelding   TEXT NULL,
    verzonden_op  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_verzonden (verzonden_op),
    KEY idx_ontvanger (ontvanger)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Inlogboek (deelnemers én beheerders) ──────────────────────────────────
CREATE TABLE IF NOT EXISTS login_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email       VARCHAR(190) NULL,
    soort       VARCHAR(30) NOT NULL,   -- code_aangevraagd | code_ok | code_fout | geblokkeerd
                                        -- | admin_ok | admin_fout | uitgelogd
    gelukt      TINYINT(1) NOT NULL DEFAULT 0,
    detail      VARCHAR(255) NULL,
    ip          VARBINARY(16) NULL,
    user_agent  VARCHAR(255) NULL,
    tijdstip    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tijdstip (tijdstip),
    KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Beheerders ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS beheerders (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    naam              VARCHAR(150) NOT NULL,
    email             VARCHAR(190) NOT NULL,
    wachtwoord_hash   VARCHAR(255) NOT NULL,
    rol               VARCHAR(20) NOT NULL DEFAULT 'beheerder',  -- beheerder | eigenaar
    actief            TINYINT(1) NOT NULL DEFAULT 1,
    laatst_ingelogd_op DATETIME NULL,
    aangemaakt_op     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Links waarmee een beheerder zelf een wachtwoord kiest ─────────────────
-- Voor een uitnodiging of na "wachtwoord vergeten". Van het geheime deel van de
-- link staat alleen een HMAC in token_hash. includes/beheerder_helper.php maakt
-- deze tabel zelf aan op installaties van vóór deze functie: houd beide gelijk.
CREATE TABLE IF NOT EXISTS beheerder_tokens (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    beheerder_id  INT UNSIGNED NOT NULL,
    doel          VARCHAR(20) NOT NULL,              -- uitnodiging | reset
    selector      CHAR(18) NOT NULL,
    token_hash    CHAR(64) NOT NULL,
    verloopt_op   DATETIME NOT NULL,
    gebruikt_op   DATETIME NULL,
    ip            VARBINARY(16) NULL,
    aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_selector (selector),
    KEY idx_beheerder (beheerder_id, gebruikt_op),
    KEY idx_verloopt (verloopt_op),
    CONSTRAINT fk_token_beheerder FOREIGN KEY (beheerder_id)
        REFERENCES beheerders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Uploads in delen die nog niet klaar zijn ─────────────────────────────
-- Eén regel per onafgemaakte upload via Beheer › Bestanden. De bytes zelf staan
-- in <OPSLAG_PAD>/.uploads/<sleutel>.deel; wat daar staat, is wat er binnen is.
-- Na afronden of annuleren verdwijnt de regel. Houd gelijk aan upload_tabel()
-- in includes/bestand_helper.php.
CREATE TABLE IF NOT EXISTS uploads (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sleutel       CHAR(32) NOT NULL,
    vingerafdruk  CHAR(64) NOT NULL,                 -- jaargang + naam + grootte + wijzigingsdatum
    jaargang_id   INT UNSIGNED NOT NULL,
    beheerder_id  INT UNSIGNED NULL,
    origineel     VARCHAR(255) NOT NULL,             -- naam op de computer van de beheerder
    naam          VARCHAR(255) NOT NULL,             -- veilige naam in de opslagmap
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Instellingen (sleutel/waarde) ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS instellingen (
    sleutel      VARCHAR(100) NOT NULL,
    waarde       TEXT NULL,
    gewijzigd_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (sleutel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Standaardinstellingen ─────────────────────────────────────────────────
INSERT INTO instellingen (sleutel, waarde) VALUES
    ('portaal_naam',            'Deventer Jeugd Musical'),
    ('portaal_welkomst',        'Vul uw e-mailadres in. U ontvangt een eenmalige inlogcode waarmee u de videoregistratie van de musical kunt downloaden.'),
    ('portaal_ingeschakeld',    '1'),
    ('contact_email',           ''),
    ('contact_tekst',           'Lukt het inloggen niet? Neem contact met ons op.'),
    ('branding_kleur',          '#0d6efd'),
    ('branding_logo_url',       ''),
    ('email_methode',           'graph'),
    ('graph_tenant_id',         ''),
    ('graph_client_id',         ''),
    ('graph_client_secret',     ''),
    ('email_van_adres',         ''),
    ('email_van_naam',          'Deventer Jeugd Musical'),
    ('smtp_host',               ''),
    ('smtp_poort',              '587'),
    ('smtp_beveiliging',        'tls'),
    ('smtp_gebruikersnaam',     ''),
    ('smtp_wachtwoord',         ''),
    ('otp_geldigheid_minuten',  '10'),
    ('otp_max_pogingen',        '5'),
    ('otp_max_per_email',       '3'),
    ('otp_max_per_ip',          '10'),
    ('otp_bind_browser',        '1'),
    ('sessie_duur_minuten',     '120'),
    ('remember_dagen',          '30'),
    ('remember_toestaan',       '1'),
    ('log_bewaartermijn_dagen', '365'),
    ('mail_onderwerp_code',     'Uw inlogcode voor {portaal_naam}'),
    ('mail_tekst_code',         'Beste {naam},\n\nUw eenmalige inlogcode is: {code}\n\nDeze code is {minuten} minuten geldig.\nHeeft u geen code aangevraagd? Dan kunt u deze e-mail negeren.'),
    ('mail_onderwerp_uitnodiging', 'Uw video staat klaar — {portaal_naam}'),
    ('mail_tekst_uitnodiging',  'Beste {naam},\n\nDe videoregistratie van de musical van {jaar} staat voor u klaar.\n\nGa naar {url} en vul uw e-mailadres in. U ontvangt dan een eenmalige inlogcode waarmee u de video kunt downloaden.')
ON DUPLICATE KEY UPDATE sleutel = sleutel;
