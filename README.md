# 🧩 MG XML Feeds

**Moduł dla PrestaShop 8–9**, służący do tworzenia, konfigurowania i generowania wielu niezależnych feedów XML z produktami sklepu.  
Umożliwia przygotowanie plików XML do integracji z zewnętrznymi systemami (np. Ceneo, Google Merchant, Allegro), blogami, portalami lub aplikacjami partnerskimi.

---

## 📦 Zakres funkcjonalny

- Tworzenie **dowolnej liczby feedów XML** z indywidualnymi ustawieniami.
- Konfiguracja obejmuje:
  - nazwy feedów i nazwy plików (`file_basename`),
  - filtry produktów (kategorie, producenci, aktywność, dostępność, ceny min/max),
  - wybór języków i walut,
  - mapowanie pól (definicja, które dane mają znaleźć się w XML),
  - automatycznie generowane tokeny: **feed_token** i **cron_token**.
- Generowanie XML:
  - ręcznie (przycisk **Build**),
  - automatycznie przez CRON (token URL).
- Kompresja `.xml.gz`, obsługa nagłówków `ETag` i `Last-Modified`.
- Logowanie wszystkich procesów generacji.
- Wsparcie dla multi-shop, multi-lang i multi-currency.

---

## ⚙️ Aktualny stan (2025-10)

- Panel administracyjny działa i zapisuje dane feedów do bazy.
- Pola JSON (`filters`, `languages`, `currencies`, `field_map`) są częściowo poprawnie składane.
- Generacja plików XML działa poprawnie (w tym CRON i ścieżki cache).
- Formularz edycji feeda działa, lecz wymaga dopracowania w zakresie:
  - zapisywania wszystkich pól konfiguracyjnych,
  - odtwarzania ustawień przy ponownym otwarciu feeda,
  - podglądu i kopiowania linków feeda / crona.

---

## 🧱 Struktura katalogów

```
mgxmlfeeds/
├── classes/
│   ├── Dto/                 # Obiekty DTO (ProductDto, FeatureDto)
│   ├── Helper/              # Pomocnicze klasy: XmlWriter, ProductQuery, Cron, File
│   ├── MgXmlFeed.php        # Model bazy danych
│   ├── MgXmlFeedLog.php     # Model logów generacji
│
├── src/
│   ├── Exporter/FeedExporter.php      # Główna logika generacji XML
│   ├── Service/FeedBuilderService.php # Budowanie danych z bazy
│
├── controllers/
│   ├── admin/AdminMgXmlFeedsController.php # Formularz i panel w BO
│   ├── front/feed.php                     # Serwowanie i podgląd XML
│   ├── front/cron.php                     # Obsługa CRON
│
├── views/
│   ├── js/admin.js               # Logika UI i JSON-ów
│   ├── css/admin.css
│   └── templates/admin/
│       ├── configure.tpl
│       └── list.tpl
│
├── var/
│   ├── cache/   # pliki XML
│   └── logs/    # logi generacji
│
├── mgxmlfeeds.php  # główny plik modułu
└── index.php       # plik ochronny
```

---

## 🧠 CRON i feedy

Każdy feed ma własny token CRON:

```
https://twojsklep.pl/module/mgxmlfeeds/cron?id=3&token=xxxxxxxxxx
```

Podgląd XML w przeglądarce:
```
https://twojsklep.pl/module/mgxmlfeeds/feed?id=3&token=xxxxxxxxxx
```

Pobranie pliku:
```
https://twojsklep.pl/module/mgxmlfeeds/feed?id=3&token=xxxxxxxxxx&download=1
```

---

## 🔧 Przykład struktury XML

```xml
<products generated_at="2025-10-16" shop_id="1" lang="pl" currency="PLN">
  <product id="4742">
    <name><![CDATA[Kompresor tłokowy HDO 50/270/680 bezolejowa]]></name>
    <product_url>https://twojsklep.pl/pl/4742-kompresor-tlokowy.html</product_url>
  </product>
</products>
```

---

## 🚧 Zadania do dokończenia

1. **Poprawny zapis JSON-ów** (`filters`, `languages`, `currencies`, `field_map`) do bazy.
2. **Odtwarzanie zaznaczonych wartości** przy edycji feeda.
3. **Wizualne generowanie tokenów** i przycisk „Kopiuj” na liście feedów.
4. **Podgląd feeda i crona** w formularzu edycji.
5. **Poprawienie JS** w `views/js/admin.js`, aby aktualizował wszystkie pola hidden JSON.
6. **Testy wielosklepowe i wielojęzykowe.**
7. **Dodanie edytora mapowania pól (field_map)** – checkbox + alias kolumny.

---

## 🧩 Co działa

✅ Generacja feeda (plik XML + komunikat „Feed built”).  
✅ Zapis podstawowych pól (`name`, `file_basename`, `token`, `cron_token`).  
✅ Wyświetlanie listy feedów.  
✅ Częściowy zapis JSON.  
✅ Automatyczna obsługa tokenów.  
✅ Zapis i odczyt nazw plików (`file_basename`).

---

## 📚 Informacje dla kolejnych deweloperów

- Moduł wymaga dopracowania zapisu i odczytu danych z formularza BO.  
- Obecnie dane konfiguracyjne są przesyłane jako hidden JSON-y — należy upewnić się, że są z nich składane poprawne wartości w `composeJsonFromPostedUi()`.  
- Skrypt `admin.js` wymaga aktualizacji, by dynamicznie odświeżał hidden JSON-y.  
- Dalszy rozwój obejmuje wizualne filtry zamiast pól JSON, obsługę mapowania pól i pełne testy CRON.

---

## 🧾 Autor i kontakt

**Truck-Experts.pl / MG Development**  
Rozwiązania diagnostyczne i integracyjne dla PrestaShop  
📧 biuro@truck-experts.pl  
🌐 https://truck-experts.pl

---

© 2025 — Rozwój modułu `mgxmlfeeds`  
Aktualizacja dokumentacji: **2025-10-16**
