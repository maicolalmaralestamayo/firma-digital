<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Firma Digital · API</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.18.2/swagger-ui.css">
    <style>
        html, body { margin: 0; padding: 0; background: #f6f7fb; }
        .topbar { display: none; }
        .header { padding: 24px 32px; background: #101a37; color: #fff; }
        .header h1 { margin: 0 0 6px; font: 600 22px/1.2 system-ui, sans-serif; }
        .header p { margin: 0; color: #b9c3e0; font: 400 14px/1.5 system-ui, sans-serif; }
        .header a { color: #9fc0ff; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Firma Digital · API REST JSON:API</h1>
        <p>
            Documento OpenAPI 3.0 ·
            <a href="{{ url('/api/v1/openapi.yaml') }}">openapi.yaml</a> ·
            <a href="{{ url('/') }}">inicio</a>
        </p>
    </div>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5.18.2/swagger-ui-bundle.js"></script>
    <script>
        window.ui = SwaggerUIBundle({
            url: @json(url('/api/v1/openapi.yaml')),
            dom_id: '#swagger-ui',
            deepLinking: true,
            displayRequestDuration: true,
            withCredentials: false,
            presets: [SwaggerUIBundle.presets.apis],
        });
    </script>
</body>
</html>
