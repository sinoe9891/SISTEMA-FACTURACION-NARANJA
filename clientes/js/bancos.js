/* bancos.js — utilidades compartidas de las pantallas de bancos y cheques */
(function () {
	const URL_ACCION = 'includes/banco_accion.php';

	function modal(id) {
		return bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
	}

	/** Envía una acción a banco_accion.php; recarga al terminar bien. */
	function accion(datos, opciones) {
		opciones = opciones || {};
		const fd = datos instanceof FormData ? datos : Object.entries(datos).reduce((f, [k, v]) => (f.append(k, v), f), new FormData());
		return fetch(URL_ACCION, { method: 'POST', body: fd })
			.then(r => r.json())
			.then(d => {
				if (!d.success) throw new Error(d.error || 'No se pudo guardar.');
				if (opciones.sinRecargar) return d;
				return Swal.fire({ icon: 'success', title: d.message, timer: 1200, showConfirmButton: false }).then(() => location.reload());
			})
			.catch(err => {
				Swal.fire('No se pudo completar', err.message, 'error');
				throw err;
			});
	}

	/** Conecta un <form> de modal: valida, envía y deshabilita el botón mientras tanto. */
	function formulario(form) {
		if (!form) return;
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			if (!form.checkValidity()) {
				form.classList.add('was-validated');
				return;
			}
			const btn = form.querySelector('[type="submit"]');
			if (btn) btn.disabled = true;
			accion(new FormData(form)).catch(() => {}).finally(() => { if (btn) btn.disabled = false; });
		});
	}

	window.Bancos = { modal, accion, formulario };
})();
