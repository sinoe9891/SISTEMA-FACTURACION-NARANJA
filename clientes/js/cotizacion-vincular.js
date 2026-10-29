(() => {
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const money = (n, c) => (c === 'USD' ? '$ ' : 'L ') + Number(n).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.addEventListener('click', async e => {
        const b = e.target.closest('.cot-vincular'); if (!b) return;
        e.preventDefault();
        try {
            const data = await fetch('includes/cotizacion_accion.php?facturas=' + encodeURIComponent(b.dataset.id)).then(r=>r.json());
            if (!data.success) throw new Error(data.error);
            if (!data.facturas.length) return Swal.fire('Sin facturas disponibles','No hay facturas emitidas sin vínculo para este cliente.','info');
            const c = data.cotizacion;
            const result = await Swal.fire({title:'Vincular factura existente',width:640,showCancelButton:true,confirmButtonText:'Vincular factura',cancelButtonText:'Cancelar',
                html:`<div class="text-start"><p><strong>${esc(c.numero)}</strong> · ${money(c.total,c.moneda)} (${esc(c.moneda)})</p><label for="cotFacturaVincular" class="form-label">Factura del mismo cliente</label><select id="cotFacturaVincular" class="form-select"><option value="">— Seleccionar factura —</option>${data.facturas.map(f=>`<option value="${Number(f.id)}">${esc(f.correlativo)} · ${esc(f.fecha_emision)} · ${money(f.moneda==='USD'?f.total_usd:f.total,f.moneda)} (${esc(f.moneda)})</option>`).join('')}</select><p id="cotMonedaAviso" class="alert alert-info small mt-3" hidden></p><p class="small text-muted">Se conserva la propuesta original. Vincular no cambia la factura ni la envía. Puede corresponder a un anticipo: no confirma el cobro ni que todo el proyecto esté facturado.</p></div>`,
                didOpen:()=>document.getElementById('cotFacturaVincular').addEventListener('change',e=>{
                    const f=data.facturas.find(f=>String(f.id)===e.target.value), a=document.getElementById('cotMonedaAviso');
                    a.hidden=!f; if(f) a.textContent=`Cotización en ${c.moneda} · factura vinculada en ${f.moneda}. Total fiscal de la factura: ${money(f.total,'HNL')}. La cotización conserva ${money(c.total,c.moneda)}.`;
                }),
                preConfirm:async()=>{
                    const fid=document.getElementById('cotFacturaVincular').value;
                    if(!fid){Swal.showValidationMessage('Selecciona una factura.');return false;}
                    const fd=new FormData();fd.set('accion','vincular_factura');fd.set('id',b.dataset.id);fd.set('factura_id',fid);
                    try {const d=await fetch('includes/cotizacion_accion.php',{method:'POST',body:fd}).then(r=>r.json());if(!d.success)throw new Error(d.error);return d;}
                    catch(err){Swal.showValidationMessage(err.message);return false;}
                }
            });
            if(result.isConfirmed) location.reload();
        } catch(err){Swal.fire('No se pudo',err.message,'error');}
    });
})();
