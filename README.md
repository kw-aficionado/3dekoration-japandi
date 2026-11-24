# 3Dekoration Japandi

Et WordPress-child-tema til [3dekoration.dk](https://3dekoration.dk/) og butikkens eksisterende Twenty Twenty-Four- og WooCommerce-blokke. Forsiden bruger de fire oprindelige billedlinks, de fem kategorier og det dynamiske produktudvalg.

![Forsiden med Japandi-temaet](screenshot.png)

## Installation

1. Behold **Twenty Twenty-Four** installeret. Temaet kræver WordPress 6.6 eller nyere og PHP 7.4 eller nyere. Brug butikkens eksisterende WooCommerce-installation.
2. Hent **3dekoration-japandi.zip** under *Assets* i [seneste release](https://github.com/kw-aficionado/3dekoration-japandi/releases/latest).
3. Åbn **Udseende → Temaer → Tilføj nyt tema → Upload tema**. Vælg ZIP-filen, installér og aktivér **3Dekoration Japandi**.
4. Til den første gennemgang kan du aktivere temaet på en stagingkopi af butikken. Så vises også de gemte sidehoveder, sidefødder og butiksskabeloner. WordPress’ forhåndsvisning før første aktivering kan vise grundtemaets standarddele.

### Hvad der følger med

Ved første aktivering kopierer temaet de publicerede skabeloner og skabelondele fra Twenty Twenty-Four, brugerens globale typografi- og layoutindstillinger, ekstra CSS samt relevante temaindstillinger til sin egen plads i WordPress. Et tidligere redigeret Japandi-layout har forrang. Efterfølgende redigering foretages som normalt i webstedseditoren.

Sider, produktkategorier, produkter, medier og menuer beholder deres eksisterende ID’er. Produktpriser, lager, variationer, kurv og betaling håndteres fortsat af WooCommerce og butikkens plugins. På en tom WordPress-installation arver temaet Twenty Twenty-Fours standardindhold.

Du kan skifte tilbage ved at aktivere **Twenty Twenty-Four** under **Udseende → Temaer**. Begge temaer husker deres egne skabelontilpasninger.

## Automatiske opdateringer fra GitHub

Fra version 1.0.0 kan det aktive tema hente stabile udgivelser anonymt fra det offentlige GitHub-repository.

1. Hvis du tidligere har sat `TREDEKORATION_GITHUB_TOKEN` i `wp-config.php` eller som miljøvariabel på webhotellet, kan du fjerne den for at bruge anonym adgang. Et udløbet token kan få GitHub til at afvise forespørgslen.
2. Åbn **Kontrolpanel → Opdateringer** og vælg **Kontrollér igen**. Et vellykket tjek rydder en eventuel tidligere advarsel.
3. Gå til **Udseende → Temaer → 3Dekoration Japandi** og vælg **Aktivér automatiske opdateringer**. WordPress installerer nye releases ved sit almindelige opdateringstjek, når baggrundsopdateringer er tilladt på hosten.

Temaet kontrollerer ZIP-filens SHA-256 mod GitHubs releaseoplysninger inden installation.

## Udgiv en ny version

1. Opdatér `Version` i `style.css`, og læg de færdige ændringer på `main`.
2. Opret et annoteret Git-tag med samme versionsnummer. Ved eksempelvis version 1.0.1 bruges `git tag -a v1.0.1 -m "Beskriv ændringen"` og `git push origin v1.0.1`.
3. GitHub Actions kontrollerer PHP, tester opdateringsfunktionen og skabelonarven i WordPress, sammenholder version og tag og udgiver den installerbare ZIP under **Releases**. Kun udgivne versioner tilbydes butikkerne.

En lokal pakke bygges med `python3 scripts/package.py` og ligger i `dist/3dekoration-japandi.zip`. ZIP-filen har den faste WordPress-mappe `3dekoration-japandi/`.
