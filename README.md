# 🌐 WebOrg Engine — Reusable Single-File PHP Landing Page & Hub

`WebOrg` to uniwersalny, niezwykle lekki i **w 100% reużywalny silnik jednoplikowy (`index.php`)** służący do automatycznego wykrywania, prezentacji oraz wizualizacji zależności dla dowolnej organizacji lub repozytorium GitHub na maszynie.

---

## 🌟 Kluczowe Cechy

1. **Jednoplikowa Architektura (`index.php`)**:
   - Wszystkie elementy (serwerowe skanowanie PHP, generowanie cache JSON, powłoka HTML5, stylizacja Dark Glassmorphic CSS, graf sieciowy Cytoscape.js i edytor README Marked.js) zawarte są w pojedynczym, czystym pliku `index.php`.
   - Wystarczy skopiować plik `index.php` do dowolnego katalogu organizacji lub podkatalogu `www/`.

2. **Automatyczne Odkrywanie (Auto-Discovery Engine)**:
   - Silnik PHP skanuje podkatalogi organizacji, ekstrahuje tytuły z plików `README.md`, automatycznie generuje zwięzłe opisy zadań w języku polskim, przypisuje tagi zastosowania oraz wykrywa powiązania i zależności między modułami.

3. **Stosowanie 24h Daily Cache**:
   - Cache zapisuje się automatycznie w pliku `cache_<org_name>.json`.
   - Jeśli wiek cache jest mniejszy niż 24 godziny (86400 sekund), dane serwowane są błyskawicznie bez ponownego skanowania dysku.
   - Pasek w nagłówku podaje dokładne odliczanie do kolejnego automatycznego odświeżenia.

4. **Wielokrotny Switcher Organizacji**:
   - Wbudowane rozwijane menu w nagłówku pozwala na dynamiczne przełączanie się pomiędzy **wszystkimi organizacjami** obecnymi w katalogu `/home/tom/github` (np. `WellManifest`, `AutoGrammar`, `WronAI`, `OQLOS`, `Subactor`, `URI-Connectors`, `Semcod`, itp.).

5. **Trzy Widoki Prezentacji**:
   - **Grid Kart**: Bogate karty projektowe z opisami zadań, tagami, zależnościami i metrykami GitHub.
   - **Graf Zależności**: Pełnoekranowy, interaktywny diagram SIECIOWY Cytoscape.js.
   - **Tabela Macierzowa**: Tabela zestawieniowa wyliczająca moduły wymagane (Upstream) oraz moduły zależne (Downstream).

---

## 🚀 Uruchomienie & Wdrożenie

### Opcja 1: Uruchomienie lokalne z PHP (CLI)
```bash
cd /home/tom/github/weborg

# Uruchomienie serwera na porcie 8000:
php -S localhost:8000 index.php
```
Otwórz w przeglądarce: `http://localhost:8000`

### Opcja 2: Uruchomienie przez NPM
```bash
npm run dev
```

### Opcja 3: Użycie w innej organizacji
Skopiuj plik `index.php` do dowolnego folderu (np. `/home/tom/github/dowolna-organizacja/www/index.php`) i uruchom serwer PHP lub skieruj swój serwer www (Apache/Nginx/PHP-FPM).

---

## 📄 Licencja

Distributed under the MIT License.
