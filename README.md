# MG XML Feeds

**Moduł dla PrestaShop 8–9**, który umożliwia generowanie wielu plików XML z produktami sklepu – do wykorzystania w integracjach, blogach WordPress, aplikacjach zewnętrznych lub systemach partnerskich.

---

## 📦 Funkcjonalność

- Generowanie **wielu niezależnych feedów XML** z produktami (każdy z własnymi filtrami i ustawieniami).
- Obsługa:
  - nazw, opisów, kategorii, producenta, zdjęć, cech, cen netto/brutto,
  - wag, wymiarów, indeksów, EAN, MPN, ISBN, linków produktowych,
  - wariantów (tryb *per product* / *per combination*),
  - multi-shop i multi-lang.
- Automatyczne generowanie i aktualizacja plików XML przez **CRON URL**.
- Kompresja GZIP (`.xml.gz`).
- Etykiety `ETag` i `Last-Modified` – dla szybszego cache przeglądarek i serwerów proxy.
- Logowanie przebiegów i błędów generacji.
- Możliwość konfiguracji:
  - filtrowania po kategoriach i producentach,
  - TTL (czas ważności cache),
  - własnych mapowań pól (JSON),
  - języków i walut (JSON).

---

## ⚙️ Instalacja

1. Skopiuj folder `mgxmlfeeds` do katalogu `/modules/` w instalacji PrestaShop.
2. W panelu administracyjnym wejdź w:
   **Moduły → Menedżer modułów → Zainstaluj moduł → Wybierz paczkę ZIP**.
3. Po instalacji znajdziesz nową sekcję:
   - **Konfiguracja** (token główny CRON, logi),
   - **XML Feeds** (lista feedów, przyciski „Edytuj”, „Generuj”).

---

## 🧩 Struktura katalogów

mgxmlfeeds/
├── classes/
│ ├── Dto/ # Obiekty DTO (ProductDto, FeatureDto)
│ ├── Helper/ # Pomocnicze klasy: XmlWriter, ProductQuery, Cron, File
│ ├── MgXmlFeed.php # Model bazy danych
│ ├── MgXmlFeedLog.php # Model logów
│
├── src/
│ ├── Exporter/FeedExporter.php # Główna logika generacji XML
│ ├── Service/FeedBuilderService.php
│
├── controllers/
│ ├── admin/AdminMgXmlFeedsController.php
│ ├── front/feed.php # Serwowanie i podgląd XML
│ ├── front/cron.php # Wywołanie CRON
│
├── translations/pl.php
│
├── views/
│ ├── css/admin.css
│ ├── js/admin.js
│ ├── templates/
│ ├── admin/
│ │ ├── configure.tpl
│ │ └── list.tpl
│ └── hook/
│ └── feed.tpl
│
├── var/
│ ├── cache/ # miejsce generowania plików XML
│ └── logs/ # logi generacji feedów
│
├── index.php # plik ochronny
└── mgxmlfeeds.php # główny plik modułu


---

## 🧠 CRON i aktualizacje

Każdy feed ma własny **token CRON**, np.:

https://twojsklep.pl/module/mgxmlfeeds/cron?id=3&token=xxxxxxxxxx


- Uruchomienie tego adresu zleca przebudowę danego pliku XML.
- Możesz dodać CRON na serwerze, np.:

```bash
wget -q -O /dev/null "https://twojsklep.pl/module/mgxmlfeeds/cron?id=3&token=xxxxxxxxxx"


Aby uruchomić wszystkie aktywne feedy:

https://twojsklep.pl/module/mgxmlfeeds/cron?all=1&token=MASTER_TOKEN


Token główny (MASTER_TOKEN) znajdziesz w konfiguracji modułu.

Podgląd feedu

Feed można podejrzeć w przeglądarce pod adresem:

https://twojsklep.pl/module/mgxmlfeeds/feed?id=3


lub pobrać czysty plik XML:

https://twojsklep.pl/module/mgxmlfeeds/feed?id=3&download=1

📄 Przykład struktury XML
<products generated_at="2025-10-09" shop_id="1" lang="pl" currency="PLN">
  <product id="1234">
    <name><![CDATA[Kompresor powietrza]]></name>
    <description><![CDATA[Profesjonalny kompresor warsztatowy 400V...]]></description>
    <manufacturer>ABAC</manufacturer>
    <category>Sprężarki</category>
    <price_tax_excl>3500.00</price_tax_excl>
    <price_tax_incl>4305.00</price_tax_incl>
    <ean13>5901234567890</ean13>
    <url>https://twojsklep.pl/kompresor-powietrza.html</url>
    <image>https://twojsklep.pl/img/p/1/2/3/123-large_default.jpg</image>
    <features>
      <feature name="Moc">5.5 kW</feature>
      <feature name="Pojemność zbiornika">270 L</feature>
    </features>
  </product>
</products>

🛠️ Dodatkowe informacje

Pliki XML są automatycznie generowane w katalogu:

/modules/mgxmlfeeds/var/cache/{id_feed}/


Logi generacji:

/modules/mgxmlfeeds/var/logs/


Moduł nie wymaga zewnętrznych bibliotek.

Kompatybilny z PrestaShop 8.0 – 9.x.

📚 Autor i kontakt

Truck-Experts.pl / MG Development
Rozwiązania diagnostyczne i integracyjne dla PrestaShop
📧 biuro@truck-experts.pl

🌐 https://truck-experts.pl