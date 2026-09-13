# DJM Portaal

Beveiligd downloadportaal voor de videoregistraties van de Deventer Jeugd Musical. PHP 8.1+,
MySQL/MariaDB, geen Composer. Nederlands in code, interface en commentaar; vier spaties.

- De vaste afspraken in de code staan in `docs/ARCHITECTUUR.md`. Lees die eerst.
- Testen: `bash test/alles.sh` (Docker). Snelle statische controle: `bash test/audit.sh`.

## Handleiding bijhouden

Beheer › Handleiding (`admin/handleiding.php`) moet bij elke functie horen die een beheerder of
deelnemer kan merken. Werk hem in dezelfde wijziging bij:

1. tekst in `admin/handleiding.php` én `docs/BEHEER.md`;
2. een nieuw scherm in `admin_menu()` krijgt een blok met `data-scherm="<naam>.php"`
   (`test/audit.sh` faalt anders);
3. schermafdrukken opnieuw: `bash test/handleiding.sh` (wist de testdatabase), en
   `assets/handleiding/` meecommitten;
4. een regel in `CHANGELOG.md`.
