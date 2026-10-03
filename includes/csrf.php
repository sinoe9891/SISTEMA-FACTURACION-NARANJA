<?php
/**
 * csrf.php — Protección contra CSRF (solicitudes falsificadas desde otros sitios).
 *
 * - Un token aleatorio por sesión.
 * - session.php rechaza todo POST/PUT/PATCH/DELETE autenticado sin el token.
 * - El navegador lo envía solo: csrf_script() agrega el token a todos los formularios
 *   POST y a todas las llamadas fetch/XMLHttpRequest del mismo sitio (header.php lo
 *   incluye en todas las páginas; las páginas sin header lo incluyen a mano).
 */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_valido(): bool
{
    $enviado = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    return is_string($enviado) && $enviado !== '' && hash_equals(csrf_token(), $enviado);
}

/** Corta la solicitud si es de escritura y no trae un token válido. */
function csrf_verificar(): void
{
    $metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($metodo, ['GET', 'HEAD', 'OPTIONS'], true) || csrf_valido()) return;

    http_response_code(403);
    $mensaje = 'La sesión expiró o la solicitud no es válida. Recarga la página e intenta de nuevo.';
    $esAjax = !empty($_SERVER['HTTP_X_CSRF_TOKEN'])
        || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'json') !== false
        || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'json') !== false
        || ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '') === 'cors'
        || !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    if ($esAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'ok' => false, 'error' => $mensaje, 'msg' => $mensaje]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<div style="font-family:system-ui;max-width:480px;margin:15vh auto;padding:24px;text-align:center">'
            . '<h2>Solicitud no válida</h2><p>' . htmlspecialchars($mensaje) . '</p>'
            . '<p><a href="javascript:history.back()">← Volver</a></p></div>';
    }
    exit;
}

/** Campo oculto para formularios escritos a mano (opcional: el script ya lo agrega). */
function csrf_input(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token()) . '">';
}

/** Meta + script que agrega el token a formularios, fetch y XMLHttpRequest. Va en el <head>. */
function csrf_script(): string
{
    $t = htmlspecialchars(csrf_token());
    return <<<HTML
<meta name="csrf-token" content="{$t}">
<script>
(function () {
	var T = document.querySelector('meta[name="csrf-token"]').content;
	function mismoSitio(u) { try { return new URL(u, location.href).origin === location.origin; } catch (e) { return true; } }
	function esEscritura(m) { m = String(m || 'GET').toUpperCase(); return m !== 'GET' && m !== 'HEAD' && m !== 'OPTIONS'; }

	// fetch()
	var fetchOriginal = window.fetch;
	if (fetchOriginal) {
		window.fetch = function (input, init) {
			init = init || {};
			var esReq = (typeof Request !== 'undefined') && input instanceof Request;
			var metodo = init.method || (esReq ? input.method : 'GET');
			var url = esReq ? input.url : String(input);
			if (esEscritura(metodo) && mismoSitio(url)) {
				var h = new Headers(init.headers || (esReq ? input.headers : undefined));
				h.set('X-CSRF-Token', T);
				init.headers = h;
			}
			return fetchOriginal.call(this, input, init);
		};
	}

	// XMLHttpRequest (jQuery.ajax, DataTables, etc.)
	var abrir = XMLHttpRequest.prototype.open, enviar = XMLHttpRequest.prototype.send;
	XMLHttpRequest.prototype.open = function (m, u) {
		this.__csrf = esEscritura(m) && mismoSitio(u);
		return abrir.apply(this, arguments);
	};
	XMLHttpRequest.prototype.send = function () {
		if (this.__csrf) this.setRequestHeader('X-CSRF-Token', T);
		return enviar.apply(this, arguments);
	};

	// Formularios POST (envío normal y form.submit() por código)
	function agregar(f) {
		if (!f || String(f.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
		if (!mismoSitio(f.getAttribute('action') || location.href) || f.querySelector('input[name="_csrf"]')) return;
		var i = document.createElement('input');
		i.type = 'hidden'; i.name = '_csrf'; i.value = T;
		f.appendChild(i);
	}
	document.addEventListener('submit', function (e) { agregar(e.target); }, true);
	var submitOriginal = HTMLFormElement.prototype.submit;
	HTMLFormElement.prototype.submit = function () { agregar(this); return submitOriginal.call(this); };
	// FormData(form) se arma antes del envío: incluir el token también ahí
	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('form').forEach(agregar);
	});
})();
</script>
HTML;
}
