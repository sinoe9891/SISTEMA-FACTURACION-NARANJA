/* app-acciones.js — envío de acciones POST a un endpoint JSON con modales y SweetAlert.
 * Uso:  const A = AppAcciones('includes/inventario_accion.php');
 *       A.formulario(formEl)                 → valida, envía y recarga
 *       A.accion({accion:'x', id:1})          → envía y recarga
 *       A.modal('idModal').show()
 */
(function () {
	function crear(url) {
		const modal = id => bootstrap.Modal.getOrCreateInstance(document.getElementById(id));

		function accion(datos, opciones) {
			opciones = opciones || {};
			const fd = datos instanceof FormData ? datos : Object.entries(datos).reduce((f, [k, v]) => (f.append(k, v), f), new FormData());
			return fetch(url, { method: 'POST', body: fd })
				.then(r => r.json())
				.then(d => {
					if (!d.success) throw new Error(d.error || 'No se pudo guardar.');
					if (opciones.sinRecargar) return d;
					return Swal.fire({ icon: 'success', title: d.message, timer: 1400, showConfirmButton: false }).then(() => location.reload());
				})
				.catch(err => {
					Swal.fire('No se pudo completar', err.message, 'error');
					throw err;
				});
		}

		function formulario(form) {
			if (!form) return;
			form.addEventListener('submit', ev => {
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

		return { modal, accion, formulario };
	}
	window.AppAcciones = crear;
})();
