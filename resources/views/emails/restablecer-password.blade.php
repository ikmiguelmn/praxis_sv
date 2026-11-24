<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>Restablecer contraseña · PRAXIS</title>
<style>
@media only screen and (max-width: 600px) {
    .card { width: 100% !important; border-radius: 0 !important; }
    .px { padding-left: 24px !important; padding-right: 24px !important; }
    .btn a { display: block !important; }
}
</style>
</head>
<body style="margin:0; padding:0; background-color:#eef2f7; -webkit-text-size-adjust:none;">
{{-- Texto de vista previa en la bandeja de entrada --}}
<div style="display:none; max-height:0; overflow:hidden; opacity:0; color:#eef2f7;">
Use este enlace para crear una nueva contraseña. Vence en {{ $minutos }} minutos.
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#eef2f7;">
<tr>
<td align="center" style="padding:40px 12px;">

    {{-- Logo --}}
    <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" class="card" style="width:560px; max-width:560px;">
    <tr>
    <td align="center" style="padding:0 0 24px 0;">
        <a href="{{ $urlInicio }}" target="_blank" style="text-decoration:none;">
            <img src="cid:praxis-logo" alt="PRAXIS" height="56" style="height:56px; width:auto; display:block; border:0;">
        </a>
    </td>
    </tr>
    </table>

    {{-- Tarjeta --}}
    <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" class="card" style="width:560px; max-width:560px; background-color:#ffffff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden;">
    <tr>
    <td style="height:4px; line-height:4px; font-size:0; background-color:#0b1b3a;">&nbsp;</td>
    </tr>
    <tr>
    <td class="px" style="padding:40px 44px 12px 44px; font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

        <h1 style="margin:0 0 12px 0; font-size:22px; line-height:30px; font-weight:700; color:#0b1b3a;">
            Restablezca su contraseña
        </h1>
        <p style="margin:0 0 28px 0; font-size:15px; line-height:24px; color:#475569;">
            Hola <strong style="color:#0b1b3a;">{{ $nombre }}</strong>, recibimos una solicitud para cambiar la contraseña de su cuenta institucional en PRAXIS.
        </p>

        {{-- Botón --}}
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" class="btn" style="margin:0 0 28px 0;">
        <tr>
        <td align="center" bgcolor="#0b1b3a" style="border-radius:8px; background-color:#0b1b3a;">
            <a href="{{ $url }}" target="_blank" rel="noopener" style="display:inline-block; padding:14px 28px; font-size:15px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:8px;">
                Crear nueva contraseña
            </a>
        </td>
        </tr>
        </table>

        {{-- Aviso de vencimiento --}}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0;">
        <tr>
        <td style="border-left:3px solid #cbd5e1; padding:2px 0 2px 14px; font-size:14px; line-height:21px; color:#475569;">
            El enlace vence en <strong style="color:#0b1b3a;">{{ $minutos }} minutos</strong> y solo puede usarse una vez.
        </td>
        </tr>
        </table>

    </td>
    </tr>

    {{-- Nota de seguridad --}}
    <tr>
    <td class="px" style="padding:24px 44px 36px 44px; font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr><td style="border-top:1px solid #e2e8f0; font-size:0; line-height:0;">&nbsp;</td></tr>
        <tr>
        <td style="padding-top:20px; font-size:13px; line-height:21px; color:#64748b;">
            <strong style="color:#0b1b3a;">¿No fue usted?</strong><br>
            Ignore este mensaje: su contraseña actual seguirá funcionando y nadie podrá cambiarla sin este enlace.
        </td>
        </tr>
        </table>
    </td>
    </tr>
    </table>

    {{-- Pie --}}
    <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" class="card" style="width:560px; max-width:560px;">
    <tr>
    <td align="center" style="padding:28px 24px 0 24px; font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12px; line-height:19px; color:#94a3b8;">
        Este es un correo automático, por favor no lo responda.<br>
        © {{ date('Y') }} PRAXIS · Gestión de Incidencias de Infraestructura Física
    </td>
    </tr>
    </table>

</td>
</tr>
</table>
</body>
</html>
