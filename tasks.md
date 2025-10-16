# 🧩 Moduł PrestaShop: mgxmlfeeds

## 📦 Opis ogólny

**mgxmlfeeds** to niestandardowy moduł dla **PrestaShop 8.x**, umożliwiający tworzenie, konfigurowanie i generowanie plików XML produktowych (feedów) na podstawie danych sklepu.

Moduł pozwala na definiowanie wielu feedów, każdy z własnymi filtrami, mapowaniem pól, walutami, językami, zakresem produktów itp.  
Obsługuje generowanie plików XML na żądanie oraz przez CRON, w oparciu o konfigurację zapisaną w bazie danych.

---

## ⚙️ Aktualny stan funkcjonalny (2025-10)

Moduł:
- działa w panelu admina PrestaShop i poprawnie tworzy oraz zapisuje feedy XML w bazie (`mg_xmlfeeds`);
- posiada formularz konfiguracyjny z wieloma polami, w tym:
  - nazwa, alias pliku (basename),
  - filtry produktów (producent, aktywność, dostępność, kategorie, ceny min/max),
  - języki i waluty,
  - mapowanie pól (na razie puste),
  - token i cron_token (generowane automatycznie);
- przy zapisie poprawnie składa JSON-y z układu formularza (`composeJsonFromPostedUi()`),
  i przekazuje je do ObjectModel;
- generuje poprawnie pliki XML (ścieżka `modules/mgxmlfeeds/var/cache/...`);
- obsługuje panel z listą feedów (`AdminMgXmlFeedsController`);
- posiada częściowo działający front formularza (JS + PHP + hidden JSON fields).

---

## 🧱 Architektura

- **ObjectModel:** `MgXmlFeed` – przechowuje dane konfiguracji feedu.
- **Controller:** `AdminMgXmlFeedsController` – obsługuje panel konfiguracyjny.
- **Frontend UI:** renderowany przez helperForm z dodatkowymi polami (selecty, checkboxy, JSON-y ukryte).
- **JSON Fields:** dane konfiguracyjne przechowywane są w kolumnach `filters`, `languages`, `currencies`, `field_map`.
- **Generator XML:** wewnętrzna funkcja generująca dane na podstawie ustawień feedu (już testowana, działa poprawnie).

---

## 🧩 Do zrobienia (taski)

### 🔧 1. Poprawki w zapisie danych (priorytet)
- [ ] Upewnić się, że **wszystkie pola JSON** (`filters`, `languages`, `currencies`, `field_map`) są poprawnie przekazywane z formularza do `$_POST` i zapisywane w bazie.
- [ ] Dodać obsługę priorytetu danych w `composeJsonFromPostedUi()`:
  ```php
  // Kolejność źródeł:
  // 1. Dane z formularza UI
  // 2. Niepusty hidden JSON
  // 3. Dane z DB (jeśli edycja)
  // 4. Pusty obiekt
