/**
 * Crear un servicio nuevo desde crear o editar contrato.
 * crearServicioContrato(receptorId) → Promise con el producto creado (o null si se cancela).
 * Queda asignado al cliente del contrato y se puede elegir de inmediato en la lista de servicios.
 */
window.crearServicioContrato = async function (receptorId) {
    if (!receptorId) {
        await Swal.fire('Elegí el cliente', 'El servicio nuevo se asigna al cliente del contrato: primero elegilo arriba.', 'info');
        return null;
    }
    const r = await Swal.fire({
        title: 'Crear servicio nuevo',
        html: '<div class="text-start">'
            + '<label class="form-label small mb-1">Nombre del servicio *</label><input id="svcN" class="form-control mb-2" maxlength="400" placeholder="Ej.: Desarrollo de sitio web informativo">'
            + '<label class="form-label small mb-1">Descripción <span class="text-muted">(opcional)</span></label><textarea id="svcD" class="form-control mb-2" rows="2" placeholder="Si la dejás vacía se usa el nombre"></textarea>'
            + '<div class="row g-2"><div class="col-7"><label class="form-label small mb-1">Precio sin ISV (L) *</label><input id="svcP" type="number" step="0.01" min="0.01" class="form-control"></div>'
            + '<div class="col-5"><label class="form-label small mb-1">ISV</label><select id="svcI" class="form-select"><option value="15">15 %</option><option value="18">18 %</option><option value="0">Exento</option></select></div></div>'
            + '<div class="form-text mt-2">Queda asignado al cliente de este contrato.</div></div>',
        showCancelButton: true, confirmButtonText: 'Crear y agregar', cancelButtonText: 'Cancelar', focusConfirm: false,
        preConfirm: async () => {
            const fd = new FormData();
            fd.append('receptor_id', receptorId);
            fd.append('nombre', document.getElementById('svcN').value);
            fd.append('descripcion', document.getElementById('svcD').value);
            fd.append('precio', document.getElementById('svcP').value);
            fd.append('tipo_isv', document.getElementById('svcI').value);
            try {
                const resp = await fetch('includes/producto_contrato_crear.php', { method: 'POST', body: fd });
                const d = await resp.json();
                if (!d.success) throw new Error(d.error || 'No se pudo crear.');
                return d.producto;
            } catch (e) { Swal.showValidationMessage(e.message); return false; }
        },
    });
    return r.isConfirmed ? r.value : null;
};
