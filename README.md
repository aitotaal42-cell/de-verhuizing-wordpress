# De Verhuizing - Website + WordPress Plugin

Complete website en WordPress formulier-plugin voor De Verhuizing, professioneel verhuisbedrijf uit Maasdijk.

## Wat zit erin?

### `/website/` - De complete statische website
- 30 HTML-pagina's (homepage, offerte, blog met 27 artikelen)
- CSS styling
- Afbeeldingen
- SEO: sitemap.xml, robots.txt
- Google Tag Manager (GTM-TNH8Q34R)
- JavaScript formulier-handlers (automatisch gekoppeld aan WordPress)

### `/wordpress-plugin/` - WordPress plugin voor formulieren
- `de-verhuizing-forms.php` - Eén bestand, alles inbegrepen
- Offerte aanvragen, terugbelverzoeken en contactberichten ontvangen
- E-mail notificaties bij elke inzending
- Overzichtspagina's in WordPress admin
- Status beheer (nieuw → in behandeling → offerte verstuurd → afgerond)
- CORS configuratie voor externe domeinen

## Installatie

### Stap 1: WordPress Plugin installeren
1. Download `wordpress-plugin/de-verhuizing-forms.php`
2. Ga naar je WordPress admin > **Plugins** > **Nieuwe plugin** > **Plugin uploaden**
3. Upload het bestand en klik op **Activeren**
4. Ga naar **De Verhuizing** > **Instellingen** in het WordPress menu
5. Controleer je notificatie e-mailadres
6. Stel je domein(en) in bij "Toegestane domeinen (CORS)"

### Stap 2: Website bestanden uploaden
1. Upload de inhoud van de `/website/` map naar je webhosting (bijv. via FTP of je hosting file manager)
2. De bestanden moeten in de root van je domein staan, of in een submap

### Stap 3: WordPress URL instellen
1. Open `website/js/forms.js`
2. Pas de eerste regel aan met jouw WordPress URL:
   ```javascript
   var DV_WORDPRESS_URL = 'https://jouwdomein.nl';
   ```
3. Upload het aangepaste bestand

### Klaar!
De formulieren op je website sturen nu automatisch gegevens naar WordPress.

## WordPress Plugin - API Endpoints

De plugin maakt deze endpoints aan op je WordPress:

| Endpoint | Methode | Beschrijving |
|----------|---------|-------------|
| `/wp-json/deverhuizing/v1/quote` | POST | Offerte aanvraag |
| `/wp-json/deverhuizing/v1/callback` | POST | Terugbelverzoek |
| `/wp-json/deverhuizing/v1/contact` | POST | Contactbericht |

## WordPress Admin

Na activering van de plugin verschijnt **De Verhuizing** in je WordPress menu met:
- **Offertes** - Alle offerte aanvragen bekijken en status wijzigen
- **Terugbellen** - Terugbelverzoeken beheren
- **Contact** - Contactberichten bekijken
- **Instellingen** - E-mail en CORS configuratie

## Beveiliging

- Alle invoer wordt gesanitized (XSS-bescherming)
- CSRF-bescherming via WordPress nonces
- E-mail validatie
- Rechtencontrole voor admin acties
- CORS beperkt tot geconfigureerde domeinen

## Bestanden overzicht

```
de-verhuizing-wordpress/
├── README.md
├── wordpress-plugin/
│   └── de-verhuizing-forms.php
└── website/
    ├── index.html (homepage)
    ├── offerte/index.html (offerte formulier)
    ├── blog/index.html (blog overzicht)
    ├── blog/[27 artikelen]/index.html
    ├── css/styles.css
    ├── js/forms.js
    ├── images/
    ├── favicon.png
    ├── sitemap.xml
    └── robots.txt
```
