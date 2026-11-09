# Firma Digital

Sistema para firmar digitalmente documentos PDF con un certificado PKCS#12
(`.p12` / `.pfx`). Estampa la imagen de la firma junto a los datos del certificado
y devuelve un PDF con firmas CMS/PAdES válidas e independientes.

- **Backend:** Laravel 12 (PHP 8.2) + PostgreSQL 14, API REST en formato
  [JSON:API 1.1](https://jsonapi.org/format/) y descripción OpenAPI 3.0.
- **Frontend:** React 19 + TypeScript + Vite (visor PDF con selección gráfica de
  la posición de la firma).

## Arquitectura

```
backend/   API Laravel (rutas en /api/v1), motor de firma y reglas de negocio
frontend/  SPA React que consume la API
```

La firma se genera con [`dskripchenko/php-pdf`](https://github.com/dskripchenko/php-pdf):
cada firmante agrega una **actualización incremental** del PDF, de modo que los
bytes ya firmados no se reescriben y las firmas anteriores siguen siendo válidas.
La estampa visible contiene la imagen subida y, al lado, los datos de la firma
criptográfica (firmante, serie, emisor, fecha y huella SHA-256 del certificado).

## Requisitos

- PHP 8.2 con `openssl`, `gd` y `pdo_pgsql`.
- Composer 2.
- PostgreSQL 14.
- Node.js 20+ y npm.

## Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
# Ajusta DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME y DB_PASSWORD en .env
php artisan migrate
```

Límites de subida recomendados en el `php.ini` usado por el servidor:

```ini
upload_max_filesize = 25M
post_max_size = 30M
memory_limit = 512M
```

Ejecución en desarrollo:

```bash
php -d upload_max_filesize=25M -d post_max_size=30M -d memory_limit=512M \
  artisan serve --host=0.0.0.0 --port=8000
```

Documentación interactiva de la API: <http://localhost:8000/api/v1/docs>
(visor Swagger UI) y la especificación cruda en
<http://localhost:8000/api/v1/openapi.yaml>.

### Variables relevantes

| Variable | Descripción |
| --- | --- |
| `FRONTEND_URL` | Origen permitido por CORS y usado en los enlaces. |
| `FIRMA_DISK` | Disco de almacenamiento de los PDF de usuarios registrados. |
| `FIRMA_MAX_PDF_KB` | Tamaño máximo del PDF (20 MB por defecto). |
| `FIRMA_MAX_CERTIFICATE_KB` | Tamaño máximo del `.p12` (512 KB). |
| `FIRMA_MAX_IMAGE_KB` | Tamaño máximo de la imagen (4 MB). |
| `FIRMA_TRUST_STORE_PATH` | Bundle PEM opcional para marcar firmas como confiables. |
| `FIRMA_DEFAULT_REASON` | Motivo por defecto de la firma. |

La clave de aplicación, las contraseñas de los certificados y los `.p12` nunca se
guardan en la base de datos: el certificado se usa en memoria y solo se persisten
sus datos públicos.

## Frontend

```bash
cd frontend
npm install
npm run dev
```

Vite sirve la SPA en <http://localhost:5173> y redirige `/api` a
`http://127.0.0.1:8000`, por lo que no hay que configurar CORS en desarrollo.
Para compilar:

```bash
npm run build
```

## API

Base: `/api/v1`. Todas las respuestas JSON usan `Content-Type:
application/vnd.api+json` y el formato de JSON:API (`data`, `included`, `links`,
`meta`, `errors`). Envía `Accept: application/vnd.api+json` o `application/json`.

Autenticación con token Bearer de Laravel Sanctum:

```http
Authorization: Bearer <token>
```

### Firma anónima

```bash
curl -X POST http://localhost:8000/api/v1/signatures \
  -H 'Accept: application/vnd.api+json' \
  -F pdf=@documento.pdf \
  -F certificate=@firma.p12 \
  -F password=secreto \
  -F image=@firma.png \
  -F page=1 -F x=60 -F y=640 -F width=240 -F height=80 \
  -o documento-firmado.pdf
```

`page` es 1-based; `x`/`y` son puntos (1 pt = 1/72") desde la esquina **superior
izquierda** de la página tal como se muestra, y `width`/`height` el tamaño de la
estampa. La respuesta es el PDF firmado (`application/pdf`) con cabeceras
`X-Signature-Sha256`, `X-Certificate-Cn`, `X-Certificate-Serial` y `X-Signed-At`.

`POST /certificates/inspect` valida la contraseña y devuelve los datos del
certificado sin firmar nada.

### Flujo con registro

1. `POST /auth/register` o `POST /auth/login` → `meta.token`.
2. `POST /documents` (multipart: `file`, `title`, `signers_required`,
   `signers[]`, `reason?`) crea el documento y reparte invitaciones. La cantidad
   de correos debe coincidir con `signers_required` (1–20).
3. Cada invitado firma con `POST /documents/{uuid}/signatures` (mismo multipart
   que la firma anónima). Cada firmante firma una sola vez.
4. Al firmar la última persona el documento queda **cerrado** (`status:
   sealed`): ya no admite firmas y los firmantes pueden descargarlo
   (`GET /documents/{uuid}/file?disposition=attachment`) o imprimirlo
   (`disposition=inline`).
5. `GET /documents` lista los documentos propios y las invitaciones; los filtros,
   la paginación y los `include` están descritos en OpenAPI.
6. `GET /documents/{uuid}/verification` informa la integridad de cada firma.

### Eliminación

- Documento **sin firmas**: `DELETE /documents/{uuid}` (solo el creador).
- Documento **firmado**: `POST /documents/{uuid}/deletion-requests` y luego cada
  firmante responde `POST /documents/{uuid}/deletion-requests/{id}/consents` con
  `approved` o `rejected`. Todos deben aprobar para que se elimine; un rechazo
  cancela la solicitud. Si el solicitante ya firmó, su aprobación es automática.

### Códigos de error estables

`invalid_pdf`, `encrypted_pdf`, `invalid_certificate`, `certificate_expired`,
`invalid_image`, `invalid_placement`, `document_sealed`, `not_a_signer`,
`already_signed`, `document_has_signatures`, `deletion_already_pending`,
`deletion_not_pending`, `no_signatures_to_consent`, `not_a_signing_party`,
`unauthenticated`, `forbidden`, `not_found`, `too_many_requests`.

## Modelo de datos

| Tabla | Contenido |
| --- | --- |
| `documents` | Documento, estado, hashes, contadores y sello final. |
| `document_signers` | Invitaciones por correo, orden y estado de firma. |
| `document_signatures` | Una fila por firma: certificado público, posición, hashes. |
| `document_deletion_requests` | Solicitud de eliminación y su estado. |
| `document_deletion_consents` | Voto de cada firmante (aprobado/rechazado). |
| `document_events` | Historial de actividad del documento. |

## Pruebas

```bash
cd backend
php artisan test
```

Los tests de feature cubren la firma anónima, autenticación, creación
multifirmante, cierre por el último firmante, descarga, verificación y
eliminación con consentimiento. Usan una base PostgreSQL `firma_digital_test`
(configurable en `phpunit.xml`).

También hay una prueba directa del motor de firma, sin HTTP ni base de datos:

```bash
php scripts/smoke-signing.php
```

## Limitaciones conocidas

- No se envían correos de invitación: la invitación queda registrada y se vincula
  automáticamente a la cuenta cuando la persona se registra o inicia sesión con
  ese correo. Para notificar por email habría que configurar un transporte de
  correo y una notificación.
- No se agrega sellado de tiempo (TSA). Si se configura `FRIMA_TRUST_STORE_PATH`,
  la verificación informa si el certificado encadena a una raíz conocida.
- Los PDF cifrados con contraseña no se pueden firmar (se rechazan con
  `encrypted_pdf`).
- El frontend implementa los flujos principales (firma anónima, registro/login,
  panel de documentos, detalle con firmantes, firmas y eliminación); no incluye
  notificaciones en tiempo real ni edición de documentos.
