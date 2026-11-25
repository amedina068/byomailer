<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
</head>
<body>
{!! \App\Support\Preheader::html($previewText ?? null) !!}
{!! $htmlContent !!}
</body>
</html>
