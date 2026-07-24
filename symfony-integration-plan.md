# Intégration Symfony <-> Pipeline OCR (FastAPI)

Plan détaillé pour la partie Symfony de scantosee : comment appeler le
service FastAPI (`main.py`), traiter la réponse JSON, et gérer les
erreurs - en mode synchrone (upload -> attente -> JSON), comme convenu.

---

## 1. Vue d'ensemble du flux

```
[Formulaire upload (front)]
        |
        v
[Controller Symfony: POST /menu/scan]
        |
        v
[Service MenuScannerClient -> HTTP POST vers FastAPI /scan-menu]
        |
        v
[FastAPI traite l'image, renvoie JSON menu]
        |
        v
[Symfony mappe le JSON -> entités Doctrine / DTO]
        |
        v
[Réponse au front: menu structuré, prêt pour l'UI de review]
```

Le call HTTP est synchrone : Symfony attend la réponse de FastAPI avant
de répondre au navigateur. Vu les temps observés en test (~15-20s pour
une image), il faut prévoir un timeout HTTP généreux côté Symfony (voir
section 4) et un indicateur de chargement côté front.

---

## 2. Configuration

### 2.1 Variable d'environnement pour l'URL du service

Dans `.env` / `.env.local` :
```
OCR_PIPELINE_URL=http://127.0.0.1:8000
```

Ne pas coder l'URL en dur dans le service - en dev c'est `localhost`,
mais si un jour le service tourne ailleurs (autre conteneur, autre
machine), un seul endroit à changer.

### 2.2 Installer le client HTTP Symfony

Si pas déjà présent :
```bash
composer require symfony/http-client
```

---

## 3. Service Symfony : `MenuScannerClient`

Créer un service dédié plutôt que d'appeler `HttpClient` directement
depuis le controller - isole la logique d'intégration, plus facile à
tester et à faire évoluer (ex: passer en asynchrone plus tard sans
toucher au controller).

**Fichier** : `src/Service/MenuScannerClient.php`

Responsabilités :
- Construire la requête multipart (image + currency)
- Envoyer le POST vers `{OCR_PIPELINE_URL}/scan-menu`
- Décoder la réponse JSON
- Convertir les erreurs HTTP (400/422/500) en exceptions Symfony
  explicites, pas juste laisser planter avec une exception HttpClient
  générique

Squelette de logique (pas le code final, juste la structure attendue) :

```php
class MenuScannerClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $ocrPipelineUrl, // injecté depuis OCR_PIPELINE_URL
    ) {}

    public function scanMenu(string $imagePath, ?string $currency = null): array
    {
        // 1. Construire le multipart avec DataPart (image) + currency
        // 2. POST vers $this->ocrPipelineUrl . '/scan-menu'
        // 3. Si status 2xx -> retourner le tableau décodé (items, orphan_prices, quality_metrics)
        // 4. Si status 400/422 -> lever une exception métier (ex: MenuScanValidationException)
        // 5. Si status 500 ou erreur réseau -> lever une exception technique (ex: MenuScanServiceException)
    }
}
```

---

## 4. Gestion du timeout

Le traitement d'une image peut prendre de 15 à 30+ secondes (chargement
modèle TrOCR à froid la première fois, puis plus rapide une fois les
modèles en cache/warmup fait côté FastAPI). Configurer un timeout HTTP
explicite dans Symfony, plus généreux que le défaut :

```php
$this->httpClient->request('POST', $url, [
    'timeout' => 60, // secondes - à ajuster selon les temps observés en prod
    // ...
]);
```

Sans ça, Symfony risque de couper la connexion avant que FastAPI ait
fini, surtout au premier appel (modèle pas encore chaud si jamais le
service redémarre).

---

## 5. Format de la requête (multipart)

Le endpoint FastAPI attend :
- `image` : fichier (le champ `File()` dans `main.py`)
- `currency` : chaîne optionnelle (ex: `"TND"`), sinon fallback sur la
  config par défaut du pipeline

Le fichier uploadé par l'utilisateur dans Symfony (`UploadedFile`) doit
être transmis tel quel, en `multipart/form-data`, pas encodé en base64
ni transformé.

---

## 6. Format de la réponse (à mapper)

Le JSON renvoyé par `/scan-menu` a cette forme (confirmé par le test
réel) :

```json
{
  "items": [
    {
      "name": "Cappuccino",
      "name_confidence": 0.33,
      "price_value": 3.0,
      "price_raw": "3.00",
      "price_ambiguous": false,
      "price_confidence": 0.40,
      "currency": "TND",
      "currency_source": "default",
      "is_category_header": false
    },
    {
      "name": "COFFEE",
      "name_confidence": 0.40,
      "price_value": null,
      "is_category_header": true
    }
  ],
  "orphan_prices": [],
  "quality_metrics": {
    "total_regions": 27,
    "items_with_prices": 12,
    "category_headers": 3,
    "pairing_success_rate": 100.0,
    "avg_confidence": 0.438,
    "low_confidence_items": 20,
    "warnings": ["Low average confidence (43.8%). Image quality may be poor."]
  },
  "debug_image_path": null
}
```

En cas d'erreur pipeline (`400`/`422`/`500`) :
```json
{"error": "RecognitionError", "message": "...", "items": []}
```

### Points d'attention pour le mapping côté Symfony

- **`is_category_header: true`** : ce ne sont pas des items à afficher
  comme plats avec prix - ce sont des titres de section ("COFFEE",
  "Non CFFEE"). L'UI de review doit les distinguer visuellement, pas
  les traiter comme des lignes de menu classiques.
- **`price_value: null`** peut arriver aussi bien pour un category
  header que pour un item dont le prix n'a pas pu être associé (voir
  `needs_review` s'il est présent - pas dans cet exemple mais possible
  selon le code du pipeline). Ne pas assumer que `null` == header.
- **`name_confidence` / `price_confidence`** : valeurs entre 0 et 1.
  Utiles pour flaguer visuellement (vert/jaune/orange/rouge) les
  lignes à faire vérifier par le patron/gérant dans l'UI de review -
  exactement l'esprit de `_confidence_band()` côté pipeline CLI.
- **`quality_metrics.warnings`** : liste de strings prêtes à afficher
  telles quelles à l'utilisateur (ou au gérant) comme bandeau d'alerte
  ("Qualité d'image possiblement faible", etc.) - à traduire/adapter
  au ton de l'app si besoin.
- **`orphan_prices`** : prix détectés mais jamais associés à un nom -
  à afficher séparément dans l'UI de review pour que le gérant les
  rattache manuellement si besoin. Ne pas les ignorer silencieusement.

---

## 7. Gestion des erreurs côté Symfony

| Cas | Origine | Comportement Symfony suggéré |
|---|---|---|
| `400 ValidationError` | image corrompue, devise invalide | Message clair à l'utilisateur : "L'image n'a pas pu être lue, réessayez avec une autre photo." |
| `422 Preprocessing/Detection/Recognition/PostprocessingError` | échec d'une étape du pipeline | Message générique : "Le traitement de l'image a échoué, réessayez." + log technique côté serveur pour debug |
| `500 Assembly/PipelineError` | échec inattendu | Message générique + alerte/log niveau erreur, potentiellement notif équipe si ça arrive souvent |
| Timeout / connexion refusée | service FastAPI down ou trop lent | Message : "Le service de scan est momentanément indisponible." + log + éventuellement retry une fois |

Ne jamais afficher la stack trace Python brute à l'utilisateur final -
toujours passer par un message Symfony traduit/normalisé.

---

## 8. Points à valider avant d'aller plus loin

- **Taille max d'upload** : Symfony a des limites par défaut
  (`upload_max_filesize` / `post_max_size` en PHP, + validation
  Symfony Form) - à aligner avec ce que FastAPI/le pipeline acceptent
  raisonnablement (une photo de menu ne devrait pas dépasser
  quelques Mo).
- **Types de fichiers acceptés** : valider côté Symfony (extension +
  mime type réel, pas juste l'extension) avant même d'envoyer à
  FastAPI, pour éviter des appels inutiles sur des fichiers
  clairement invalides.
- **Un seul environnement pour l'instant** : pas de gestion
  multi-environnement (dev/staging/prod) nécessaire tant qu'on reste
  en local - juste la variable d'env `OCR_PIPELINE_URL` suffit pour
  l'instant.

---

## 9. Hors scope pour l'instant (noté pour plus tard)

- Traitement asynchrone (queue + polling ou webhook) si les temps de
  traitement deviennent un problème UX.
- Authentification entre Symfony et FastAPI (pas nécessaire tant que
  les deux tournent en local sur la même machine).
- Dockerisation des deux services.
- Retry automatique en cas d'échec transitoire.
