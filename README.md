# FEIDE WordPress Authentication Plugin

En WordPress-plugin som autentiserer brukere mot FEIDE via OpenID Connect/OAuth 2.0.

## Beskrivelse

Denne pluginen lar deg integrere FEIDE-autentisering i WordPress, med avanserte muligheter for:
- Automatisk brukeroppretting
- Fleksibel attributt-mapping
- Rolle-tildeling basert på FEIDE-attributter
- Test-funksjonalitet for å se alle mottatte attributter

## Funksjoner

### OpenID Connect-integrasjon
- Full støtte for OAuth 2.0 / OpenID Connect-flyt
- Sikker token-håndtering
- CSRF-beskyttelse med state-parameter

### Konfigurerbart Admin-panel
- Enkelt oppsett av alle OpenID-parametre
- Client ID og Client Secret
- Konfigurerbare endpoints
- Redirect/Callback URL-administrasjon

### Test-funksjonalitet
- Test innlogging direkte fra admin-panelet
- Vis alle mottatte attributter fra FEIDE
- Debugging-verktøy for attributt-mapping

### Attributt-mapping
- Map FEIDE-attributter til WordPress-brukerfelter
- Støtte for nested attributter (f.eks. `user:id`)
- Konfigurerbare felt:
  - Brukernavn
  - E-post
  - Fornavn
  - Etternavn
  - Visningsnavn

### Avansert rolle-tildeling
- Definer kriterier basert på FEIDE-attributter
- Støtte for AND/OR-logikk
- Flere sammenligningsoperatorer:
  - Er lik
  - Inneholder
  - Starter med
  - Slutter med
  - Er ikke lik
- Tildel forskjellige WordPress-roller basert på attributter
- Fleksibelt system med flere regler

### Automatisk brukeroppretting
- Opprett nye brukere automatisk ved første innlogging
- Konfigurerbar on/off
- Automatisk tildeling av roller basert på kriterier

## Installasjon

1. Last ned eller klon dette repositoriet til WordPress plugin-mappen:
   ```bash
   cd /path/to/wordpress/wp-content/plugins/
   git clone https://github.com/myonlyeye/fida.git feide-wordpress-auth
   ```

2. Aktiver pluginen i WordPress admin-panelet under "Plugins"

3. Gå til "Innstillinger" → "FEIDE Autentisering" for å konfigurere

## Konfigurasjon

### 1. OpenID-innstillinger

Gå til admin-panelet og fyll inn følgende felter:

#### Obligatoriske felt:
- **Client ID**: Din applikasjons Client ID fra FEIDE
- **Client Secret**: Din applikasjons Client Secret fra FEIDE
- **Redirect/Callback URL**: URL hvor FEIDE skal sende brukeren tilbake (må registreres hos FEIDE)

#### Standard FEIDE-endpoints (kan endres ved behov):
- **Authorize Endpoint**: `https://auth.dataporten.no/oauth/authorization`
- **Access Token Endpoint**: `https://auth.dataporten.no/oauth/token`
- **Get User Info Endpoint**: `https://auth.dataporten.no/userinfo`
- **Group User Info Endpoint**: `https://groups-api.dataporten.no/groups/me/groups`

#### Andre innstillinger:
- **Scope**: `openid profile email` (kan utvides etter behov)
- **Automatisk oppretting av brukere**: Kryss av for å aktivere

### 2. Test autentisering

1. Gå til fanen "Test Autentisering"
2. Klikk på "Test FEIDE-innlogging"
3. Logg inn med din FEIDE-konto
4. Se alle attributter som mottas fra FEIDE
5. Bruk denne informasjonen til å konfigurere attributt-mapping og rolle-tildeling

### 3. Attributt-mapping

Gå til fanen "Attributt-mapping" for å definere hvordan FEIDE-attributter skal mappes til WordPress-brukerfelter:

- **Brukernavn**: Standard `sub` (FEIDE bruker-ID)
- **E-post**: Standard `email`
- **Fornavn**: Standard `given_name`
- **Etternavn**: Standard `family_name`
- **Visningsnavn**: Standard `name`

For nested attributter, bruk kolon som separator: `parent:child:value`

### 4. Rolletildeling

Gå til fanen "Rolletildeling" for å definere hvilke brukere som får tilgang og hvilke roller de skal tildeles.

#### Eksempel 1: Spillpedagog-kommune
Lag en rolleregel med følgende kriterier:
- **WordPress-rolle**: Velg eller opprett "Spillpedagog-kommune"
- **Operator**: AND (alle kriterier må være oppfylt)
- **Kriterier**:
  - Attributt: `eduPersonOrgUnitDN:norEduOrgUnitUniqueIdentifier`
  - Sammenligning: Er lik
  - Verdi: `[verdi for kommune]`

#### Eksempel 2: Spillpedagog-skole
Lag en ny rolleregel:
- **WordPress-rolle**: Velg eller opprett "Spillpedagog-skole"
- **Operator**: AND
- **Kriterier**:
  - Attributt: `eduPersonOrgDN:norEduOrgNIN`
  - Sammenligning: Er lik
  - Verdi: `[verdi for skole]`

#### Eksempel 3: Flere alternativer (OR-logikk)
For å tillate flere attributter som gir samme rolle:
- **Operator**: OR (minst ett kriterium må være oppfylt)
- Legg til flere kriterier med "Legg til kriterium"

#### Eksempel 4: Wildcard for gruppemedlemskap
Bruk wildcard (`*`) for å sjekke medlemskap i grupper uten å kjenne eksakt indeks:
- **WordPress-rolle**: Redaktør
- **Operator**: AND
- **Kriterier**:
  - Attributt: `groups:*:id`
  - Sammenligning: Er lik
  - Verdi: `fc:adhoc:abc-123-def-456`

Dette matcher hvis brukeren er medlem i EN ELLER FLERE grupper der minst én gruppe har `id = fc:adhoc:abc-123-def-456`.

**Andre wildcard-eksempler:**
- `groups:*:displayName` - Match gruppenavn (f.eks. "Lærere", "Administrasjon")
- `groups:*:membership:basic` - Match medlemskapstype
- `user:orgs:*:role` - Match rolle i hvilken som helst organisasjon

**Fordeler med wildcards:**
- ✅ Slipper å lage separate regler for hver gruppeindeks
- ✅ Fungerer automatisk selv om antall grupper endres
- ✅ Enklere vedlikehold

## Bruk

### For sluttbrukere
1. Gå til WordPress innloggingsside
2. Klikk på "Logg inn med FEIDE"
3. Logg inn med din FEIDE-konto
4. Blir automatisk opprettet og logget inn i WordPress

### For administratorer
- Administrer roller og tilgangskriterier i admin-panelet
- Test konfigurasjonen uten å påvirke produksjon
- Overvåk hvilke attributter som mottas fra FEIDE

## Sikkerhet

Pluginen implementerer flere sikkerhetstiltak:
- CSRF-beskyttelse med state-parameter og nonces
- Sikker token-håndtering
- Transient-basert session-håndtering
- Sanitering av alle brukerinput
- WordPress nonces for AJAX-kall

## Feilsøking

### Vanlige problemer og løsninger

#### Problem: "Ugyldig state-parameter" eller "Mulig CSRF-angrep"
**Årsak:** State-parameteren har utløpt (10 minutter) eller cookies er blokkert.

**Løsning:**
1. Prøv å logge inn på nytt
2. Sjekk at nettleseren tillater cookies
3. Hvis problemet vedvarer, sjekk om server-tid er korrekt synkronisert

#### Problem: "Mottok ikke access token fra FEIDE" eller "Wrong client credentials"
**Årsak:** Feil Client ID/Secret eller redirect URI mismatch.

**Løsning:**
1. Verifiser at **Client ID** og **Client Secret** er korrekte i Settings-fanen
2. Kontroller at **Redirect URI** i WordPress matcher **nøyaktig** det som er registrert hos FEIDE
   - Standard: `https://dittdomene.no/wp-login.php?feide-auth=callback`
   - Må være identisk (inkludert http vs https)
3. Test med "Test FEIDE-innlogging" funksjonen først
4. Sjekk debug-fanen for detaljert feilmelding

#### Problem: "Du har ikke tilgang til dette systemet"
**Årsak:** Brukeren oppfyller ikke noen av rolle-reglene som er konfigurert.

**Løsning:**
1. Gå til **Debug-fanen** og se "Siste kriterium-sjekk" - denne viser nøyaktig hvorfor tilgang ble nektet
2. Sammenlign attributt-verdiene som ble mottatt med forventet verdi i rolle-regelen
3. **Hurtigfiks:** Aktiver "Gi alle autentiserte FEIDE-brukere tilgang" i Settings-fanen
4. Sjekk at attributt-stier er riktige (f.eks. `groups:0:id` ikke `group_info:0:id`)
5. Husk at sammenligning er case-insensitive

**Eksempel debug-output:**
```
Attributt: groups:0:displayName
Faktisk verdi: "Lærere"
Forventet verdi: "laerere"
Resultat: MATCH (case-insensitive)
```

#### Problem: Brukeren opprettes ikke automatisk
**Årsak:** Auto-create er deaktivert eller bruker oppfyller ikke kriterier.

**Løsning:**
1. Gå til **Settings → Automatisk oppretting av brukere** og aktiver
2. Sjekk at brukeren oppfyller minst én rolleregel ELLER at "Gi alle tilgang" er aktivert
3. Sjekk WordPress debug-log (`wp-content/debug.log`) for feilmeldinger
4. Verifiser at e-postadresse mottas fra FEIDE (se Test-fanen)

#### Problem: FEIDE-knappen vises ikke på innloggingssiden
**Årsak:** Plugin ikke konfigurert eller JavaScript/CSS ikke lastet.

**Løsning:**
1. Sjekk at plugin er aktivert i WordPress
2. Gå til Settings og fyll inn minst Client ID, Client Secret og Authorize Endpoint
3. Tøm nettleser-cache og WordPress cache
4. Sjekk at `assets/css/login.css` og `assets/js/login.js` eksisterer og er lesbare

#### Problem: "Failed innlogging" eller timeout-feil
**Årsak:** FEIDE-servere er trege eller utilgjengelige.

**Løsning:**
1. Alle API-kall har 15 sekunders timeout - vent og prøv igjen
2. Sjekk at FEIDE Dataporten er tilgjengelig: https://status.dataporten.no/
3. Kontakt FEIDE support hvis problemet vedvarer

#### Problem: Attributter vises som NULL i test-resultater
**Årsak:** Feil scope eller attributtet finnes ikke for brukeren.

**Løsning:**
1. Sjekk at scope inkluderer `openid profile email` (minimum)
2. Noen attributter krever ekstra scopes (f.eks. `groups` for gruppeinformasjon)
3. Test med en annen FEIDE-bruker som har attributtene

### Debug-verktøy

#### Aktivere WordPress debug-logging
Legg til i `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Loggfil: `wp-content/debug.log`

#### Bruke Test-funksjonen
1. Gå til **Test Autentisering-fanen**
2. Klikk "Test FEIDE-innlogging"
3. Se alle attributter som mottas fra FEIDE
4. Kopier attributt-stiene direkte til rolle-regler

#### Bruke Debug-fanen
Debug-fanen viser:
- **Siste attributter mottatt fra FEIDE** - full JSON-dump
- **Siste kriterium-sjekk** - detaljert sammenligning av hver regel
- **Siste tilgangsnekting** - hvorfor en bruker ble nektet tilgang
- **Lagrede innstillinger** - gjeldende konfigurering

### Kontakt og support

**For FEIDE-relaterte spørsmål:**
- FEIDE kundesenter: https://www.feide.no/
- FEIDE dokumentasjon: https://docs.feide.no/

**For plugin-problemer:**
- GitHub Issues: https://github.com/myonlyeye/fida/issues
- Sjekk CHANGELOG.md for kjente problemer

## Tekniske detaljer

### Filstruktur
```
feide-wordpress-auth/
├── feide-wordpress-auth.php    # Hovedfil med activation/deactivation hooks
├── uninstall.php                # Cleanup ved avinstallering
├── includes/
│   ├── class-feide-wp-auth.php        # Hovedklasse
│   └── class-feide-authenticator.php  # Autentiseringslogikk
├── admin/
│   └── class-feide-admin.php          # Admin-panel (5 faner)
├── assets/
│   ├── css/
│   │   ├── admin.css                  # Admin-panel styling
│   │   └── login.css                  # Innloggingsside styling
│   └── js/
│       ├── admin.js                   # Admin-panel JavaScript
│       └── login.js                   # Innloggingsside JavaScript
├── README.md                           # Denne filen
└── CHANGELOG.md                        # Versjonhistorikk
```

### Hooks og Filters
Pluginen bruker standard WordPress hooks:
- `plugins_loaded`: Initialiserer plugin
- `admin_menu`: Legger til admin-meny
- `admin_init`: Registrerer innstillinger
- `init`: Håndterer OAuth callback
- `login_form`: Legger til FEIDE-knapp på innloggingsside

### Database
Pluginen lagrer innstillinger i WordPress options-tabell:
- `feide_wp_auth_settings`: Alle plugin-innstillinger

Brukermetadata lagres per bruker:
- `feide_attributes`: Alle FEIDE-attributter fra siste innlogging
- `feide_last_login`: Tidspunkt for siste innlogging

## Lisens

GPL v2 or later

## Support

For spørsmål eller problemer, opprett en issue på GitHub:
https://github.com/myonlyeye/fida/issues

## Bidrag

Bidrag er velkommen! Send gjerne pull requests.

## Changelog

### 1.1.0
- Forbedret innloggingsknapp med ikon og bedre design
- Valgbar plassering av innloggingsknapp (over eller under WordPress-innlogging)
- Elegant skillestrek mellom FEIDE og WordPress innlogging
- Forbedret test-side som viser attributt-stier som kan brukes direkte
- Omfattende debug-verktøy med detaljert kriterium-sjekk
- Forbedret attributt-sammenligning (case-insensitive, array-håndtering)
- Logging av alle innloggingsforsøk for enklere feilsøking

### 1.0.0
- Første versjon
- OpenID Connect-integrasjon
- Attributt-mapping
- Rolle-tildeling basert på attributter
- Test-funksjonalitet
- Automatisk brukeroppretting
