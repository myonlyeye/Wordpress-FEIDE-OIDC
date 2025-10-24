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

### Problem: "Ugyldig state-parameter"
- Dette kan skyldes at transienten har utløpt (10 minutter)
- Prøv å logge inn på nytt

### Problem: "Mottok ikke access token"
- Sjekk at Client ID og Client Secret er riktige
- Sjekk at Redirect URI er registrert hos FEIDE
- Se på respons-meldingen for mer informasjon

### Problem: "Du har ikke tilgang til dette systemet"
- Sjekk at rolle-kriteriene er riktig konfigurert
- Bruk test-funksjonen for å se hvilke attributter brukeren har
- Verifiser at attributt-navn og verdier matcher

### Problem: Brukeren opprettes ikke automatisk
- Sjekk at "Automatisk oppretting av brukere" er aktivert
- Sjekk at brukeren oppfyller minst én rolleregel
- Se i WordPress debug-log for feilmeldinger

## Tekniske detaljer

### Filstruktur
```
feide-wordpress-auth/
├── feide-wordpress-auth.php    # Hovedfil
├── includes/
│   ├── class-feide-wp-auth.php        # Hovedklasse
│   └── class-feide-authenticator.php  # Autentiseringslogikk
├── admin/
│   └── class-feide-admin.php          # Admin-panel
├── assets/
│   ├── css/
│   │   └── admin.css                  # Admin-styling
│   └── js/
│       └── admin.js                   # Admin-JavaScript
└── README.md                           # Denne filen
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
